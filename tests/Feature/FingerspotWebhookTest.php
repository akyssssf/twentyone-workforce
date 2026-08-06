<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\DeviceCallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FingerspotWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected string $secret = 'rahasiaujicobapanjang32karakteroke';

    protected function url(?string $secret = null): string
    {
        return '/api/fingerspot/webhook/'.($secret ?? $this->secret);
    }

    /**
     * @return array<string, mixed>
     */
    protected function attlogPayload(): array
    {
        return [
            'type' => 'attlog',
            'cloud_id' => 'XXXXX',
            'data' => [
                'pin' => '1',
                'scan' => '2026-08-06 09:07',
                'verify' => '1',
                'status_scan' => '0',
            ],
        ];
    }

    public function test_callback_sah_tersimpan_utuh_dan_balas_200(): void
    {
        $payload = $this->attlogPayload();

        $this->postJson($this->url(), $payload)
            ->assertOk()
            ->assertJson(['success' => true]);

        $callback = DeviceCallback::sole();

        $this->assertSame('attlog', $callback->type);
        $this->assertSame('XXXXX', $callback->cloud_id);
        $this->assertSame($payload, $callback->payload);

        // Push spontan attlog memang tidak membawa trans_id.
        $this->assertNull($callback->trans_id);

        // Controller hanya mengarsip. Pengolahan urusan parser.
        $this->assertFalse($callback->parsed);
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_secret_salah_ditolak_404(): void
    {
        // Panjang sama, beda satu karakter terakhir.
        $this->postJson($this->url('rahasiaujicobapanjang32karakterok0'), $this->attlogPayload())
            ->assertNotFound();

        $this->assertSame(0, DeviceCallback::count());
    }

    public function test_tanpa_secret_ditolak_404(): void
    {
        $this->postJson('/api/fingerspot/webhook/', $this->attlogPayload())->assertNotFound();
        $this->postJson('/api/fingerspot/webhook', $this->attlogPayload())->assertNotFound();

        $this->assertSame(0, DeviceCallback::count());
    }

    public function test_secret_kosong_di_config_menutup_endpoint(): void
    {
        // Penjagaan terhadap salah konfigurasi: hash_equals('', '') bernilai
        // true, jadi tanpa penjagaan khusus endpoint akan terbuka lebar.
        config(['fingerspot.webhook_secret' => '']);

        $this->postJson($this->url(), $this->attlogPayload())->assertNotFound();

        $this->assertSame(0, DeviceCallback::count());
    }

    public function test_body_bukan_json_tetap_diarsipkan(): void
    {
        // Scan tidak boleh hilang cuma karena body-nya rusak.
        $this->call(
            'POST', $this->url(), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            'ini bukan json',
        )->assertOk();

        $callback = DeviceCallback::sole();

        $this->assertTrue($callback->payload['_invalid_json']);
        $this->assertSame('ini bukan json', $callback->payload['_raw']);
        $this->assertNull($callback->type);
    }

    public function test_callback_tipe_lain_menyimpan_trans_id(): void
    {
        $this->postJson($this->url(), [
            'type' => 'get_userinfo',
            'cloud_id' => 'XXXXX',
            'trans_id' => '42',
            'data' => ['pin' => '1', 'name' => 'Budi'],
        ])->assertOk();

        $callback = DeviceCallback::sole();

        $this->assertSame('get_userinfo', $callback->type);
        $this->assertSame('42', $callback->trans_id);
    }

    public function test_webhook_hanya_menerima_post(): void
    {
        $this->getJson($this->url())->assertMethodNotAllowed();
    }

    /**
     * Alur ujung ke ujung: mesin kirim scan, cron parser mengolahnya.
     */
    public function test_alur_penuh_dari_webhook_sampai_attendance_log(): void
    {
        $this->postJson($this->url(), $this->attlogPayload())->assertOk();

        $this->artisan('attendance:parse-callbacks')->assertSuccessful();

        $log = AttendanceLog::sole();

        $this->assertSame('1', $log->pin);
        $this->assertSame('2026-08-06 09:07:00', $log->scanned_at->format('Y-m-d H:i:s'));
        $this->assertSame('webhook', $log->source);
        $this->assertTrue(DeviceCallback::sole()->parsed);
    }

    public function test_command_kebal_dijalankan_berulang(): void
    {
        $this->postJson($this->url(), $this->attlogPayload())->assertOk();

        $this->artisan('attendance:parse-callbacks')->assertSuccessful();
        $this->artisan('attendance:parse-callbacks')->assertSuccessful();

        $this->assertSame(1, AttendanceLog::count());
    }
}
