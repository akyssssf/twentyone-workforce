<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Services\Fingerspot\AttlogSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AttlogSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fingerspot.api_token' => 'token-uji',
            'fingerspot.cloud_id' => 'GQ5179086',
            'fingerspot.api_url' => 'https://developer.fingerspot.io/api',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00', 'Asia/Jakarta'));

        // Tes ini tidak boleh menyentuh API sungguhan.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function sync(): AttlogSynchronizer
    {
        return app(AttlogSynchronizer::class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function fakeAttlog(array $rows): void
    {
        Http::fake(['*/get_attlog' => Http::response([
            'success' => true, 'trans_id' => '1', 'data' => $rows,
        ])]);
    }

    public function test_menarik_scan_dan_menyimpannya_sebagai_sync(): void
    {
        $this->fakeAttlog([
            ['pin' => '1', 'scan_date' => '2026-08-09 09:07:29', 'verify' => 1, 'status_scan' => 0],
            ['pin' => '1', 'scan_date' => '2026-08-09 17:03:11', 'verify' => 1, 'status_scan' => 1],
        ]);

        $hasil = $this->sync()->syncRange(
            Carbon::parse('2026-08-09'), Carbon::parse('2026-08-09'),
        );

        $this->assertSame(2, $hasil['created']);
        $this->assertSame(0, $hasil['duplicate']);
        $this->assertSame(2, AttendanceLog::count());

        $log = AttendanceLog::orderBy('scanned_at')->first();
        $this->assertSame('sync', $log->source);
        $this->assertSame('GQ5179086', $log->cloud_id);
        $this->assertSame('2026-08-09 09:07:29', $log->scanned_at->format('Y-m-d H:i:s'));
        // Kunci duplikat dipangkas ke menit.
        $this->assertSame('2026-08-09 09:07:00', $log->scan_minute->format('Y-m-d H:i:s'));

        // Baris hasil tarikan tidak berasal dari callback.
        $this->assertNull($log->device_callback_id);
    }

    /**
     * Inti jalur cadangan: menambal yang kelewat tanpa menggandakan yang
     * sudah ada.
     */
    public function test_scan_yang_sudah_masuk_lewat_webhook_tidak_digandakan(): void
    {
        // Webhook lebih dulu menyimpan, presisi menit.
        $at = Carbon::parse('2026-08-09 09:07:00', 'Asia/Jakarta');
        AttendanceLog::create([
            'cloud_id' => 'GQ5179086', 'pin' => '1', 'scanned_at' => $at,
            'scan_minute' => $at->copy()->startOfMinute(), 'source' => 'webhook',
        ]);

        // Cron menarik scan yang sama, presisi detik.
        $this->fakeAttlog([
            ['pin' => '1', 'scan_date' => '2026-08-09 09:07:29', 'verify' => 1, 'status_scan' => 0],
            ['pin' => '1', 'scan_date' => '2026-08-09 17:03:11', 'verify' => 1, 'status_scan' => 1],
        ]);

        $hasil = $this->sync()->syncRange(Carbon::parse('2026-08-09'), Carbon::parse('2026-08-09'));

        $this->assertSame(1, $hasil['created']);    // hanya scan pulang yang baru
        $this->assertSame(1, $hasil['duplicate']);
        $this->assertSame(2, AttendanceLog::count());

        // Baris asli dari webhook tidak tertimpa.
        $this->assertSame('webhook', AttendanceLog::orderBy('scanned_at')->first()->source);
    }

    public function test_menarik_ulang_rentang_yang_sama_tidak_menambah_baris(): void
    {
        $this->fakeAttlog([
            ['pin' => '1', 'scan_date' => '2026-08-09 09:07:29', 'verify' => 1, 'status_scan' => 0],
        ]);

        $this->sync()->syncRange(Carbon::parse('2026-08-09'), Carbon::parse('2026-08-09'));
        $kedua = $this->sync()->syncRange(Carbon::parse('2026-08-09'), Carbon::parse('2026-08-09'));

        $this->assertSame(0, $kedua['created']);
        $this->assertSame(1, $kedua['duplicate']);
        $this->assertSame(1, AttendanceLog::count());
    }

    // ------------------------------------------------------- pemotongan rentang

    public function test_rentang_panjang_dipotong_maksimal_dua_hari(): void
    {
        $potongan = iterator_to_array($this->sync()->chunks(
            Carbon::parse('2026-08-01'), Carbon::parse('2026-08-07'),
        ));

        $this->assertCount(4, $potongan);

        $terbaca = array_map(
            fn ($p) => $p[0]->toDateString().'..'.$p[1]->toDateString(),
            $potongan,
        );

        $this->assertSame([
            '2026-08-01..2026-08-02',
            '2026-08-03..2026-08-04',
            '2026-08-05..2026-08-06',
            '2026-08-07..2026-08-07',
        ], $terbaca);
    }

    public function test_satu_hari_jadi_satu_potongan(): void
    {
        $potongan = iterator_to_array($this->sync()->chunks(
            Carbon::parse('2026-08-09'), Carbon::parse('2026-08-09'),
        ));

        $this->assertCount(1, $potongan);
    }

    public function test_rentang_panjang_menghasilkan_beberapa_permintaan(): void
    {
        $this->fakeAttlog([]);

        $hasil = $this->sync()->syncRange(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-06'));

        $this->assertSame(3, $hasil['chunks']);
        Http::assertSentCount(3);
    }

    /**
     * Data lebih dari 60 hari sudah dihapus Fingerspot. Lebih baik mundur ke
     * batas retensi daripada mengirim permintaan yang pasti gagal.
     */
    public function test_permintaan_dimundurkan_ke_batas_retensi(): void
    {
        $potongan = iterator_to_array($this->sync()->chunks(
            Carbon::parse('2025-01-01'), Carbon::parse('2026-08-10'),
        ));

        $awal = $potongan[0][0];

        $this->assertSame('2026-06-11', $awal->toDateString());
        $this->assertTrue($awal->greaterThanOrEqualTo(Carbon::today('Asia/Jakarta')->subDays(60)));
    }

    public function test_tanggal_terbalik_ditolak(): void
    {
        $this->expectException(\App\Services\Fingerspot\FingerspotException::class);

        iterator_to_array($this->sync()->chunks(
            Carbon::parse('2026-08-09'), Carbon::parse('2026-08-01'),
        ));
    }

    // ------------------------------------------------------------- ketahanan

    public function test_respons_kosong_bukan_kegagalan(): void
    {
        // Belum ada yang scan itu keadaan normal, bukan error.
        $this->fakeAttlog([]);

        $hasil = $this->sync()->syncRange(Carbon::parse('2026-08-09'), Carbon::parse('2026-08-09'));

        $this->assertSame(0, $hasil['created']);
        $this->assertSame(0, $hasil['rows']);
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_baris_rusak_dilewati_tanpa_menggagalkan_sisanya(): void
    {
        $this->fakeAttlog([
            ['pin' => '1', 'scan_date' => '2026-08-09 09:07:29', 'verify' => 1, 'status_scan' => 0],
            ['scan_date' => '2026-08-09 10:00:00'],                       // tanpa pin
            ['pin' => '2', 'scan_date' => 'bukan tanggal'],               // waktu ngawur
            ['pin' => '3', 'scan_date' => '2026-08-09 11:11:11', 'verify' => 1, 'status_scan' => 0],
        ]);

        $hasil = $this->sync()->syncRange(Carbon::parse('2026-08-09'), Carbon::parse('2026-08-09'));

        $this->assertSame(2, $hasil['created']);
        $this->assertSame(2, $hasil['invalid']);
        $this->assertSame(2, AttendanceLog::count());
    }

    public function test_token_ditolak_dilaporkan_jelas(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthorized'], 401)]);

        $this->expectException(\App\Services\Fingerspot\FingerspotException::class);
        $this->expectExceptionMessage('Token ditolak Fingerspot');

        $this->sync()->syncRange(Carbon::parse('2026-08-09'), Carbon::parse('2026-08-09'));
    }

    public function test_foto_wajah_ikut_tertarik(): void
    {
        // Mesin Vivo W-2421M memotret wajah tiap scan.
        $this->fakeAttlog([[
            'pin' => '1', 'scan_date' => '2026-08-09 09:07:29', 'verify' => 4, 'status_scan' => 0,
            'photo_url' => 'https://s3.example.com/foto/abc.jpg',
        ]]);

        $this->sync()->syncRange(Carbon::parse('2026-08-09'), Carbon::parse('2026-08-09'));

        $this->assertSame('https://s3.example.com/foto/abc.jpg', AttendanceLog::sole()->photo_url);
    }

    // -------------------------------------------------------------- command

    public function test_command_menarik_dan_melaporkan(): void
    {
        $this->fakeAttlog([
            ['pin' => '1', 'scan_date' => '2026-08-09 09:07:29', 'verify' => 1, 'status_scan' => 0],
        ]);

        $this->artisan('attendance:sync', ['--from' => '2026-08-09', '--to' => '2026-08-09'])
            ->expectsOutputToContain('Menarik data 2026-08-09')
            ->assertSuccessful();

        $this->assertSame(1, AttendanceLog::count());
    }

    public function test_command_memberi_tahu_kalau_tidak_ada_data(): void
    {
        $this->fakeAttlog([]);

        $this->artisan('attendance:sync', ['--from' => '2026-08-09', '--to' => '2026-08-09'])
            ->expectsOutputToContain('tidak mengembalikan satu baris pun')
            ->assertSuccessful();
    }

    public function test_command_menolak_tanggal_terbalik(): void
    {
        $this->artisan('attendance:sync', ['--from' => '2026-08-09', '--to' => '2026-08-01'])
            ->expectsOutputToContain('tidak boleh melewati')
            ->assertFailed();
    }

    public function test_command_gagal_rapi_kalau_token_ditolak(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthorized'], 401)]);

        $this->artisan('attendance:sync', ['--from' => '2026-08-09', '--to' => '2026-08-09'])
            ->expectsOutputToContain('Token ditolak Fingerspot')
            ->assertFailed();
    }
}
