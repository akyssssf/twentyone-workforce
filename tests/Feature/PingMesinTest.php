<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DeviceCallback;
use App\Models\Employee;
use App\Models\Shift;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `fingerspot:ping` — apakah MESIN masih terhubung, bukan cuma cloud-nya.
 *
 * Waktu scan berhenti masuk, dua keadaan terlihat persis sama dari sisi
 * aplikasi: mesin kehilangan koneksi, dan mesin terhubung tapi memang tidak ada
 * yang scan. `fingerspot:check` tidak bisa membedakannya — dia memanggil
 * get_device, yang cuma membaca catatan cloud dan tetap berhasil walaupun
 * mesinnya sudah tercabut seminggu.
 *
 * get_userinfo bersifat asinkron: MESIN yang harus mengeksekusi lalu menjawab
 * lewat webhook. Jadi datangnya callback adalah bukti, dan diamnya juga.
 */
class PingMesinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);

        config([
            'fingerspot.api_token' => 'token-uji',
            'fingerspot.cloud_id' => 'GQ5179086',
            'fingerspot.api_url' => 'https://developer.fingerspot.io/api',
        ]);

        Http::preventStrayRequests();

        Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Karyawan Uji',
            'pin_device' => '10',
            'default_shift_id' => Shift::where('code', 'pagi')->firstOrFail()->id,
        ]);
    }

    /** Mesin menjawab: callback dibuat dari trans_id yang benar-benar dikirim. */
    protected function fakeDenganJawaban(bool $menjawab): void
    {
        Http::fake(['*/get_userinfo' => function ($request) use ($menjawab) {
            $transId = (string) ($request->data()['trans_id'] ?? '');

            if ($menjawab) {
                DeviceCallback::create([
                    'cloud_id' => 'GQ5179086',
                    'type' => 'get_userinfo',
                    'trans_id' => $transId,
                    'payload' => ['type' => 'get_userinfo', 'trans_id' => $transId, 'data' => ['pin' => '10']],
                    'parsed' => false,
                    'received_at' => Carbon::now(),
                ]);
            }

            return Http::response(['success' => true, 'trans_id' => $transId]);
        }]);
    }

    public function test_mesin_menjawab_berarti_terhubung(): void
    {
        $this->fakeDenganJawaban(true);

        $this->artisan('fingerspot:ping --tunggu=5')
            ->assertSuccessful()
            ->expectsOutputToContain('Mesin MENJAWAB');
    }

    /**
     * Diamnya mesin adalah bukti, bukan ketidaktahuan — dan kode keluarnya
     * harus gagal supaya tidak terbaca beres oleh skrip mana pun.
     */
    public function test_mesin_diam_dilaporkan_tidak_terhubung(): void
    {
        $this->fakeDenganJawaban(false);

        $this->artisan('fingerspot:ping --tunggu=1')
            ->assertFailed()
            ->expectsOutputToContain('TIDAK menjawab');
    }

    /** Gagal menghubungi API itu perkara lain, dan tidak boleh disalahartikan. */
    public function test_api_gagal_dibedakan_dari_mesin_diam(): void
    {
        Http::fake(['*/get_userinfo' => Http::response(['success' => false, 'message' => 'token ditolak'], 200)]);

        $this->artisan('fingerspot:ping --tunggu=1')
            ->assertFailed()
            ->expectsOutputToContain('belum bilang apa-apa soal mesinnya');
    }

    public function test_memakai_pin_yang_diminta(): void
    {
        $this->fakeDenganJawaban(true);

        $this->artisan('fingerspot:ping --pin=19 --tunggu=5')->assertSuccessful();

        Http::assertSent(fn ($request) => ($request->data()['pin'] ?? null) === '19');
    }
}
