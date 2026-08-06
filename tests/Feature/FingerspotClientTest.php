<?php

namespace Tests\Feature;

use App\Services\Fingerspot\FingerspotClient;
use App\Services\Fingerspot\FingerspotException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FingerspotClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fingerspot.api_token' => 'token-uji',
            'fingerspot.cloud_id' => 'XXXXX',
            'fingerspot.api_url' => 'https://developer.fingerspot.io/api',
        ]);

        // Jaring pengaman: tes ini tidak boleh menyentuh API sungguhan.
        Http::preventStrayRequests();
    }

    protected function client(): FingerspotClient
    {
        return new FingerspotClient;
    }

    public function test_get_device_mengembalikan_data_perangkat(): void
    {
        Http::fake(['*/get_device' => Http::response([
            'success' => true,
            'trans_id' => '1',
            'data' => [
                'cloud_id' => 'XXXXX',
                'device_name' => 'device 1',
                'webhook_url' => 'https://contoh.test/webhook',
            ],
        ])]);

        $device = $this->client()->getDevice();

        $this->assertSame('device 1', $device['device_name']);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer token-uji')
                && $request['cloud_id'] === 'XXXXX'
                && ! empty($request['trans_id']);
        });
    }

    public function test_token_kosong_ditolak_sebelum_kirim(): void
    {
        config(['fingerspot.api_token' => '']);

        $this->expectException(FingerspotException::class);
        $this->expectExceptionMessage('FINGERSPOT_API_TOKEN belum diisi');

        $this->client()->getDevice();
    }

    public function test_cloud_id_kosong_ditolak(): void
    {
        config(['fingerspot.cloud_id' => '']);

        $this->expectException(FingerspotException::class);
        $this->expectExceptionMessage('FINGERSPOT_CLOUD_ID belum diisi');

        $this->client()->getDevice();
    }

    public function test_token_ditolak_memberi_pesan_yang_jelas(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthorized'], 401)]);

        $this->expectException(FingerspotException::class);
        $this->expectExceptionMessage('Token ditolak Fingerspot');

        $this->client()->getDevice();
    }

    /**
     * API ini bisa membalas HTTP 200 tapi menandai kegagalan lewat field
     * success. Status code saja tidak cukup jadi patokan.
     */
    public function test_http_200_dengan_success_false_dianggap_gagal(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'Device not found'], 200)]);

        $this->expectException(FingerspotException::class);
        $this->expectExceptionMessage('Device not found');

        $this->client()->getDevice();
    }

    public function test_get_attlog_mengirim_rentang_tanggal_yang_benar(): void
    {
        Http::fake(['*/get_attlog' => Http::response([
            'success' => true,
            'trans_id' => '1',
            'data' => [
                ['pin' => '1', 'scan_date' => '2026-08-05 09:07:29', 'verify' => 1, 'status_scan' => 0],
            ],
        ])]);

        $rows = $this->client()->getAttlog(
            Carbon::parse('2026-08-05'),
            Carbon::parse('2026-08-06'),
        );

        $this->assertCount(1, $rows);

        Http::assertSent(function ($request) {
            return $request['start_date'] === '2026-08-05'
                && $request['end_date'] === '2026-08-06';
        });
    }

    public function test_rentang_lebih_dari_dua_hari_ditolak_sebelum_kirim(): void
    {
        // Batas dokumentasi ditegakkan di client, bukan dipercayakan pemanggil.
        $this->expectException(FingerspotException::class);
        $this->expectExceptionMessage('melebihi batas 2 hari');

        $this->client()->getAttlog(
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-05'),
        );
    }

    public function test_rentang_tepat_dua_hari_diterima(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []])]);

        $rows = $this->client()->getAttlog(
            Carbon::parse('2026-08-05'),
            Carbon::parse('2026-08-06'),
        );

        $this->assertSame([], $rows);
    }

    public function test_rentang_satu_hari_diterima(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []])]);

        $this->client()->getAttlog(Carbon::parse('2026-08-06'), Carbon::parse('2026-08-06'));

        Http::assertSent(fn ($request) => $request['start_date'] === $request['end_date']);
    }

    public function test_tanggal_di_luar_retensi_60_hari_ditolak(): void
    {
        Carbon::setTestNow('2026-08-06 12:00:00');

        $this->expectException(FingerspotException::class);
        $this->expectExceptionMessage('di luar retensi 60 hari');

        $this->client()->getAttlog(
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-01-01'),
        );
    }

    public function test_start_date_setelah_end_date_ditolak(): void
    {
        $this->expectException(FingerspotException::class);
        $this->expectExceptionMessage('tidak boleh melewati end_date');

        $this->client()->getAttlog(
            Carbon::parse('2026-08-06'),
            Carbon::parse('2026-08-05'),
        );
    }

    public function test_perangkat_tanpa_scan_mengembalikan_array_kosong(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'trans_id' => '1', 'data' => []])]);

        $this->assertSame([], $this->client()->getAttlog(
            Carbon::parse('2026-08-06'),
            Carbon::parse('2026-08-06'),
        ));
    }

    public function test_jaringan_putus_dibungkus_jadi_fingerspot_exception(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Timeout'));

        $this->expectException(FingerspotException::class);
        $this->expectExceptionMessage('Gagal menghubungi get_device');

        $this->client()->getDevice();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
