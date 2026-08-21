<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DeviceCallback;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\Fingerspot\FaceTemplate;
use App\Services\Fingerspot\FingerspotClient;
use App\Services\Fingerspot\FingerspotException;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pendaftaran wajah jarak jauh lewat set_userinfo.
 *
 * Yang dijaga paling keras di sini bukan bentuk payload-nya, tapi kejujuran
 * laporannya: `set_userinfo` asinkron, jadi "terkirim" dan "terdaftar" adalah
 * dua hal berbeda. Perintah yang menyamakan keduanya akan bilang berhasil
 * padahal mesin menolak, dan bedanya baru ketahuan saat orangnya berdiri di
 * depan mesin lalu tidak dikenali.
 */
class DaftarWajahTest extends TestCase
{
    use RefreshDatabase;

    protected string $foto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);

        config([
            'fingerspot.api_token' => 'token-uji',
            'fingerspot.cloud_id' => 'XXXXX',
            'fingerspot.api_url' => 'https://developer.fingerspot.io/api',
        ]);

        Http::preventStrayRequests();

        // Waktu dibekukan supaya trans_id (timestamp milidetik) bisa ditebak
        // tesnya, sehingga callback tiruan bisa disiapkan lebih dulu.
        Carbon::setTestNow(Carbon::parse('2026-08-22 09:00:00', 'Asia/Jakarta'));

        Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Karyawan Baru',
            'pin_device' => '21',
            'default_shift_id' => Shift::where('code', 'pagi')->firstOrFail()->id,
        ]);

        $this->foto = $this->berkasJpeg(20 * 1024);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach (glob(sys_get_temp_dir().'/uji-wajah-*') ?: [] as $sisa) {
            @unlink($sisa);
        }

        parent::tearDown();
    }

    /** JPEG palsu: byte pembuka asli, sisanya isian sebesar yang diminta. */
    protected function berkasJpeg(int $ukuran, string $pembuka = "\xFF\xD8\xFF"): string
    {
        $path = tempnam(sys_get_temp_dir(), 'uji-wajah-');
        file_put_contents($path, $pembuka.str_repeat('a', max(0, $ukuran - strlen($pembuka))));

        return $path;
    }

    /**
     * Tiruan Fingerspot menerima perintah.
     *
     * $status yang diisi berarti mesin ikut menjawab: callback-nya dibuat dari
     * trans_id yang BENAR-BENAR dikirim, bukan dari nilai yang ditebak tes.
     * Menebaknya pernah menyembunyikan bug sungguhan — mesin memangkas trans_id
     * kegedean, dan tes yang memakai nilai tebakannya sendiri tidak akan pernah
     * melihat itu.
     */
    protected function fakeTerima(?string $status = null): void
    {
        Http::fake(['*/set_userinfo' => function ($request) use ($status) {
            $transId = (string) ($request->data()['trans_id'] ?? '');

            if ($status !== null) {
                $this->jawabanMesin($transId, $status);
            }

            return Http::response(['success' => true, 'trans_id' => $transId]);
        }]);
    }

    protected function jawabanMesin(string $transId, string $status): void
    {
        DeviceCallback::create([
            'cloud_id' => 'XXXXX',
            'type' => 'set_userinfo',
            'trans_id' => $transId,
            'payload' => ['type' => 'set_userinfo', 'cloud_id' => 'XXXXX', 'trans_id' => $transId, 'data' => ['status' => $status]],
            'parsed' => false,
            'received_at' => Carbon::now(),
        ]);
    }

    public function test_mesin_mengonfirmasi_wajah_terdaftar(): void
    {
        $this->fakeTerima('1');

        $this->artisan("employee:daftar-wajah 21 {$this->foto}")
            ->assertSuccessful();
    }

    /** Status 2 = ditolak mesin. Tidak boleh terbaca sebagai berhasil. */
    public function test_penolakan_mesin_dilaporkan_gagal(): void
    {
        $this->fakeTerima('2');

        $this->artisan("employee:daftar-wajah 21 {$this->foto}")
            ->assertFailed();
    }

    /**
     * Regresi dari kejadian nyata: trans_id 1787319375917 (timestamp milidetik)
     * dipantulkan mesin sebagai 2147483647 — dipangkas ke batas atas int32.
     * Akibatnya callback-nya tidak pernah cocok, dan pendaftaran yang SEBENARNYA
     * BERHASIL terlaporkan sebagai "mesin tidak menjawab". Semua nilai kegedean
     * dipangkas ke angka yang sama, jadi dua perintah pun jadi tak terbedakan.
     */
    public function test_trans_id_muat_di_integer_32_bit(): void
    {
        $this->fakeTerima('1');

        $this->artisan("employee:daftar-wajah 21 {$this->foto}")->assertSuccessful();

        Http::assertSent(function ($request) {
            $transId = $request->data()['trans_id'] ?? null;

            return $transId !== null
                && ctype_digit((string) $transId)
                && (int) $transId >= 1
                && (int) $transId <= FingerspotClient::MAX_TRANS_ID;
        });
    }

    /**
     * Tidak ada jawaban bukan berarti berhasil. Kode keluarnya sengaja bukan
     * sukses supaya skrip mana pun tidak menganggapnya beres.
     */
    public function test_tanpa_jawaban_mesin_tidak_dianggap_berhasil(): void
    {
        $this->fakeTerima();

        $this->artisan("employee:daftar-wajah 21 {$this->foto} --tunggu=1")
            ->assertFailed();
    }

    /** --tunggu=0 berarti sengaja tidak menunggu, dan itu harus dikatakan. */
    public function test_tanpa_menunggu_dikatakan_belum_tentu_terdaftar(): void
    {
        $this->fakeTerima();

        $this->artisan("employee:daftar-wajah 21 {$this->foto} --tunggu=0")
            ->assertSuccessful()
            ->expectsOutputToContain('Belum tentu terdaftar');
    }

    /** Foto kegedean ditolak SEBELUM dikirim — di mesin gagalnya tanpa keterangan. */
    public function test_foto_lebih_dari_100kb_ditolak_sebelum_dikirim(): void
    {
        $besar = $this->berkasJpeg(150 * 1024);

        $this->artisan("employee:daftar-wajah 21 {$besar}")->assertFailed();

        Http::assertNothingSent();
    }

    /** Nama berkas .jpg tidak membuktikan apa-apa; isinya yang diperiksa. */
    public function test_berkas_bukan_jpeg_ditolak(): void
    {
        $png = $this->berkasJpeg(10 * 1024, "\x89PNG");

        $this->artisan("employee:daftar-wajah 21 {$png}")->assertFailed();

        Http::assertNothingSent();
    }

    public function test_pin_tidak_terdaftar_ditolak(): void
    {
        $this->artisan("employee:daftar-wajah 999 {$this->foto}")->assertFailed();

        Http::assertNothingSent();
    }

    /** Base64 ganda: lapis luar JSON, lapis dalam JPEG. */
    public function test_template_disusun_base64_ganda(): void
    {
        $jpeg = "\xFF\xD8\xFF".'isi-foto';

        $template = FaceTemplate::dariJpeg($jpeg);

        $luar = json_decode(base64_decode($template), true);

        $this->assertSame(['face'], array_keys($luar));
        $this->assertSame($jpeg, base64_decode($luar['face']));
    }

    public function test_template_menolak_foto_kegedean(): void
    {
        $this->expectException(FingerspotException::class);

        FaceTemplate::dariJpeg("\xFF\xD8\xFF".str_repeat('a', FaceTemplate::MAX_BYTES));
    }
}
