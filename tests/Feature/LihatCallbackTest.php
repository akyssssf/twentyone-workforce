<?php

namespace Tests\Feature;

use App\Models\DeviceCallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `fingerspot:callback` — membaca jawaban asinkron dari mesin.
 *
 * Perintah seperti set_userinfo tidak pernah mengembalikan hasilnya di respons
 * HTTP. Tanpa pembaca ini, jawaban satu-satunya saat mesin telat menjawab
 * adalah "tidak tahu", dan seluruh alur asinkron jadi tidak bisa didiagnosis.
 */
class LihatCallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function buat(string $transId, ?string $status, ?string $type = 'set_userinfo'): void
    {
        DeviceCallback::create([
            'cloud_id' => 'XXXXX',
            'type' => $type,
            'trans_id' => $transId,
            'payload' => ['type' => $type, 'cloud_id' => 'XXXXX', 'trans_id' => $transId]
                + ($status === null ? [] : ['data' => ['status' => $status]]),
            'parsed' => false,
            'received_at' => Carbon::now(),
        ]);
    }

    protected function jalankan(string $argumen = ''): string
    {
        Artisan::call(trim('fingerspot:callback '.$argumen));

        return Artisan::output();
    }

    public function test_callback_sukses_dilaporkan_sukses(): void
    {
        $this->buat('1787319375917', '1');

        $this->artisan('fingerspot:callback 1787319375917')->assertSuccessful();
        $this->assertStringContainsString('sukses', $this->jalankan('1787319375917'));
    }

    /** Status 2 harus keluar dengan kode gagal, bukan sekadar ditampilkan. */
    public function test_callback_gagal_dilaporkan_gagal(): void
    {
        $this->buat('1787319375917', '2');

        $this->artisan('fingerspot:callback 1787319375917')->assertFailed();
    }

    /**
     * Yang paling penting: belum ada jawaban tidak boleh terbaca sebagai beres,
     * dan harus menyebut kemungkinan penyebabnya — kalau tidak, orang cuma tahu
     * "tidak ada" tanpa tahu harus memeriksa apa.
     */
    public function test_belum_ada_jawaban_bukan_berarti_beres(): void
    {
        $this->artisan('fingerspot:callback 1787319375917')->assertFailed();

        $keluaran = $this->jalankan('1787319375917');

        $this->assertStringContainsString('Belum ada callback', $keluaran);
        $this->assertStringContainsString('webhook_url', $keluaran);
    }

    /** Tanpa argumen: apakah webhook-nya hidup sama sekali. */
    public function test_daftar_terbaru_menampilkan_scan_dan_jawaban_perintah(): void
    {
        $this->buat('1787319375917', '1');

        DeviceCallback::create([
            'cloud_id' => 'XXXXX',
            'type' => 'attlog',
            'trans_id' => null,
            'payload' => ['type' => 'attlog', 'data' => ['pin' => '23', 'scan' => '2026-08-21 13:34']],
            'parsed' => false,
            'received_at' => Carbon::now(),
        ]);

        $keluaran = $this->jalankan();

        $this->assertStringContainsString('set_userinfo', $keluaran);
        $this->assertStringContainsString('attlog', $keluaran);
        $this->assertStringContainsString('pin 23', $keluaran);
    }

    public function test_belum_pernah_ada_callback_sama_sekali(): void
    {
        $this->artisan('fingerspot:callback')->assertFailed();
        $this->assertStringContainsString('Belum pernah ada', $this->jalankan());
    }
}
