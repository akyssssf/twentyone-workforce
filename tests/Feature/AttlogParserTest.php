<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\DeviceCallback;
use App\Services\Fingerspot\AttlogParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttlogParserTest extends TestCase
{
    use RefreshDatabase;

    protected AttlogParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = app(AttlogParser::class);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function makeCallback(array $payload, string $type = 'attlog'): DeviceCallback
    {
        return DeviceCallback::create([
            'cloud_id' => $payload['cloud_id'] ?? null,
            'type' => $type,
            'trans_id' => $payload['trans_id'] ?? null,
            'payload' => $payload,
            'ip' => '127.0.0.1',
            'parsed' => false,
            'received_at' => Carbon::now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function attlogPayload(array $data = []): array
    {
        return [
            'type' => 'attlog',
            'cloud_id' => 'XXXXX',
            'data' => array_merge([
                'pin' => '1',
                'scan' => '2026-08-06 09:07',
                'verify' => '1',
                'status_scan' => '0',
            ], $data),
        ];
    }

    public function test_payload_webhook_normal_jadi_satu_baris_log(): void
    {
        $callback = $this->makeCallback($this->attlogPayload());

        $result = $this->parser->parseCallback($callback);

        $this->assertSame(['created' => 1, 'duplicate' => 0, 'skipped' => false], $result);

        $log = AttendanceLog::sole();

        $this->assertSame('XXXXX', $log->cloud_id);
        $this->assertSame('1', $log->pin);
        $this->assertSame('2026-08-06 09:07:00', $log->scanned_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $log->verify_mode);
        $this->assertSame(0, $log->status_scan);
        $this->assertSame('webhook', $log->source);

        // Jejak balik ke arsip mentah harus terpasang.
        $this->assertSame($callback->id, $log->device_callback_id);

        // Fingerspot tidak punya io_mode.
        $this->assertNull($log->io_mode);

        $this->assertTrue($callback->fresh()->parsed);
        $this->assertNull($callback->fresh()->parse_error);
    }

    public function test_waktu_scan_ditafsirkan_di_zona_jakarta(): void
    {
        $callback = $this->makeCallback($this->attlogPayload());

        $this->parser->parseCallback($callback);

        $this->assertSame(
            'Asia/Jakarta',
            AttendanceLog::sole()->scanned_at->timezone->getName(),
        );
    }

    public function test_scan_minute_memangkas_detik(): void
    {
        // Bentuk get_attlog: pakai kunci scan_date dan berpresisi detik.
        $callback = $this->makeCallback([
            'type' => 'attlog',
            'cloud_id' => 'XXXXX',
            'data' => ['pin' => '1', 'scan_date' => '2026-08-06 09:07:29', 'verify' => '1', 'status_scan' => '0'],
        ]);

        $this->parser->parseCallback($callback);

        $log = AttendanceLog::sole();

        // Presisi asli dipertahankan...
        $this->assertSame('2026-08-06 09:07:29', $log->scanned_at->format('Y-m-d H:i:s'));
        // ...tapi kunci duplikatnya dipangkas.
        $this->assertSame('2026-08-06 09:07:00', $log->scan_minute->format('Y-m-d H:i:s'));
    }

    /**
     * Inti dari seluruh desain anti-duplikat: scan yang sama datang lewat dua
     * jalur dengan presisi waktu berbeda, dan hanya boleh jadi satu baris.
     */
    public function test_scan_sama_dari_cron_dan_webhook_tidak_dobel(): void
    {
        // Cron get_attlog sudah lebih dulu menambal, presisi detik.
        $scannedAt = Carbon::parse('2026-08-06 09:07:29', 'Asia/Jakarta');

        AttendanceLog::create([
            'cloud_id' => 'XXXXX',
            'pin' => '1',
            'scanned_at' => $scannedAt,
            'scan_minute' => $scannedAt->copy()->startOfMinute(),
            'source' => 'sync',
        ]);

        // Webhook menyusul dengan scan yang sama, presisi menit.
        $callback = $this->makeCallback($this->attlogPayload(['scan' => '2026-08-06 09:07']));

        $result = $this->parser->parseCallback($callback);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['duplicate']);
        $this->assertSame(1, AttendanceLog::count());

        // Duplikat bukan kegagalan: callback tetap dianggap selesai.
        $this->assertTrue($callback->fresh()->parsed);
        $this->assertNull($callback->fresh()->parse_error);
    }

    public function test_memproses_callback_yang_sama_dua_kali_tidak_menambah_baris(): void
    {
        $callback = $this->makeCallback($this->attlogPayload());

        $this->parser->parseCallback($callback);
        $result = $this->parser->parseCallback($callback->fresh());

        $this->assertSame(1, AttendanceLog::count());
        $this->assertSame(1, $result['duplicate']);
    }

    public function test_scan_beda_menit_tetap_masuk_terpisah(): void
    {
        $this->parser->parseCallback($this->makeCallback($this->attlogPayload(['scan' => '2026-08-06 09:07'])));
        $this->parser->parseCallback($this->makeCallback($this->attlogPayload(['scan' => '2026-08-06 17:03'])));

        $this->assertSame(2, AttendanceLog::count());
    }

    public function test_pin_berbeda_di_menit_sama_tetap_masuk(): void
    {
        $this->parser->parseCallback($this->makeCallback($this->attlogPayload(['pin' => '1'])));
        $this->parser->parseCallback($this->makeCallback($this->attlogPayload(['pin' => '2'])));

        $this->assertSame(2, AttendanceLog::count());
    }

    public function test_callback_bukan_attlog_ditandai_selesai_tanpa_bikin_log(): void
    {
        $callback = $this->makeCallback([
            'type' => 'get_userinfo',
            'cloud_id' => 'XXXXX',
            'trans_id' => '42',
            'data' => ['pin' => '1', 'name' => 'Budi'],
        ], type: 'get_userinfo');

        $result = $this->parser->parseCallback($callback);

        $this->assertTrue($result['skipped']);
        $this->assertSame(0, AttendanceLog::count());
        $this->assertTrue($callback->fresh()->parsed);

        // Bukan error, jadi tidak boleh ada parse_error.
        $this->assertNull($callback->fresh()->parse_error);
    }

    /**
     * Payload rusak permanen harus berhenti diulang, tapi alasannya tetap
     * terekam supaya bisa ditelusuri.
     *
     * @param  array<string, mixed>  $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('payloadRusak')]
    public function test_payload_rusak_ditandai_dengan_parse_error(array $payload, string $petunjuk): void
    {
        $callback = $this->makeCallback($payload);

        $result = $this->parser->parseCallback($callback);

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, AttendanceLog::count());

        $fresh = $callback->fresh();
        $this->assertTrue($fresh->parsed, 'Payload rusak permanen tidak boleh terus diulang.');
        $this->assertNotNull($fresh->parse_error);
        $this->assertStringContainsString($petunjuk, $fresh->parse_error);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function payloadRusak(): array
    {
        return [
            'tanpa data' => [
                ['type' => 'attlog', 'cloud_id' => 'XXXXX'],
                'data',
            ],
            'tanpa pin' => [
                ['type' => 'attlog', 'cloud_id' => 'XXXXX', 'data' => ['scan' => '2026-08-06 09:07']],
                'pin',
            ],
            'tanpa waktu' => [
                ['type' => 'attlog', 'cloud_id' => 'XXXXX', 'data' => ['pin' => '1']],
                'waktu',
            ],
            'format waktu ngawur' => [
                ['type' => 'attlog', 'cloud_id' => 'XXXXX', 'data' => ['pin' => '1', 'scan' => '06/08/2026 09:07']],
                'Format waktu',
            ],
            'tanggal tidak ada di kalender' => [
                ['type' => 'attlog', 'cloud_id' => 'XXXXX', 'data' => ['pin' => '1', 'scan' => '2026-02-31 09:07']],
                'Format waktu',
            ],
            'json rusak dari controller' => [
                ['_invalid_json' => true, '_raw' => 'ini bukan json'],
                'data',
            ],
        ];
    }

    public function test_pin_nol_dianggap_sah(): void
    {
        // "0" gampang tertelan kalau pengecekannya pakai empty().
        $callback = $this->makeCallback($this->attlogPayload(['pin' => '0']));

        $this->parser->parseCallback($callback);

        $this->assertSame('0', AttendanceLog::sole()->pin);
    }

    /**
     * Parser ini nantinya dipakai ulang jalur cron get_attlog, yang mengirim
     * "data" sebagai array objek, bukan objek tunggal.
     */
    public function test_data_berbentuk_array_menghasilkan_banyak_baris(): void
    {
        $callback = $this->makeCallback([
            'type' => 'attlog',
            'cloud_id' => 'XXXXX',
            'data' => [
                ['pin' => '1', 'scan_date' => '2026-08-06 09:07:29', 'verify' => 1, 'status_scan' => 0],
                ['pin' => '2', 'scan_date' => '2026-08-06 09:08:02', 'verify' => 4, 'status_scan' => 0],
            ],
        ]);

        $result = $this->parser->parseCallback($callback);

        $this->assertSame(2, $result['created']);
        $this->assertSame(2, AttendanceLog::count());
    }

    /**
     * Mesin di sini Vivo W-2421M, seri yang memotret wajah tiap scan.
     */
    public function test_foto_wajah_ditarik_ke_kolom_sendiri(): void
    {
        $callback = $this->makeCallback($this->attlogPayload([
            'photo_url' => 'https://s3.example.com/foto/abc123.jpg',
        ]));

        $this->parser->parseCallback($callback);

        $this->assertSame('https://s3.example.com/foto/abc123.jpg', AttendanceLog::sole()->photo_url);
    }

    public function test_mesin_tanpa_kamera_tidak_dianggap_bermasalah(): void
    {
        $this->parser->parseCallback($this->makeCallback($this->attlogPayload()));

        $this->assertNull(AttendanceLog::sole()->photo_url);
    }

    public function test_kode_mesin_di_luar_kamus_tetap_disimpan_apa_adanya(): void
    {
        // Mesin non-Fingerspot boleh mengirim kode sendiri, jangan ditolak.
        $callback = $this->makeCallback($this->attlogPayload(['verify' => '99', 'status_scan' => '77']));

        $this->parser->parseCallback($callback);

        $log = AttendanceLog::sole();

        $this->assertSame(99, $log->verify_mode);
        $this->assertSame(77, $log->status_scan);
        $this->assertSame('99', $log->verifyModeLabel());
    }
}
