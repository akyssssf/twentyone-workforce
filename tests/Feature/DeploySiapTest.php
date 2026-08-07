<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Penjaga hal-hal yang baru terasa akibatnya setelah aplikasi online.
 */
class DeploySiapTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sandi awal buatan admin harus diganti sebelum apa pun bisa dibuka.
     *
     * Sandi itu pernah diketahui minimal dua orang, dan sering tercatat di
     * kertas atau chat. Selama belum diganti, siapa pun yang sempat melihatnya
     * bisa membuka slip gaji orang itu.
     */
    public function test_sandi_awal_wajib_diganti_sebelum_membuka_halaman_lain(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Admin,
            'must_change_password' => true,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('sandi.edit'));
        $this->actingAs($user)->get(route('sandi.edit'))->assertOk();
    }

    public function test_ganti_sandi_membuka_kembali_akses(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Admin,
            'must_change_password' => true,
            'password' => Hash::make('sandilama'),
        ]);

        $this->actingAs($user)->post(route('sandi.update'), [
            'sandi_lama' => 'sandilama',
            'password' => 'sandibarupanjang',
            'password_confirmation' => 'sandibarupanjang',
        ])->assertRedirect(route('beranda'));

        $this->assertFalse($user->fresh()->must_change_password);
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
    }

    /** Sandi lama tetap diminta, bahkan saat sedang dipaksa ganti. */
    public function test_tidak_bisa_ganti_sandi_tanpa_sandi_lama(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
            'password' => Hash::make('sandilama'),
        ]);

        $this->actingAs($user)->post(route('sandi.update'), [
            'sandi_lama' => 'tebakan',
            'password' => 'sandibarupanjang',
            'password_confirmation' => 'sandibarupanjang',
        ])->assertSessionHasErrors('sandi_lama');

        $this->assertTrue($user->fresh()->must_change_password);
    }

    /**
     * Salinan database harus bisa dipulihkan, bukan sekadar ada.
     *
     * Dipakai VACUUM INTO, bukan menyalin berkas, karena selalu ada yang
     * menulis — mesin mengirim scan kapan saja — dan menyalin file yang sedang
     * ditulis menghasilkan salinan rusak yang baru ketahuan saat dibutuhkan.
     */
    /**
     * Backup menolak jalan di dalam transaksi, dengan pesan yang berguna.
     *
     * Uji ini berjalan di dalam transaksi RefreshDatabase, jadi yang terbukti
     * di sini adalah penjagaannya — bukan pembuatan berkasnya. Itu memang yang
     * penting dijaga: tanpa penjagaan ini, kegagalannya muncul sebagai galat
     * SQL mentah yang tidak memberi tahu cara membetulkannya.
     */
    public function test_backup_menolak_jalan_di_dalam_transaksi(): void
    {
        $this->assertGreaterThan(0, \Illuminate\Support\Facades\DB::transactionLevel());

        $this->artisan('db:backup')
            ->expectsOutputToContain('Tidak bisa membuat salinan di dalam transaksi')
            ->assertFailed();
    }

    /**
     * Mekanisme salinannya sendiri: hasilnya harus bisa dibuka dan memuat
     * skema yang sama.
     *
     * Dipakai berkas sungguhan, bukan database uji di memori, karena yang
     * sedang diuji justru perilaku VACUUM INTO terhadap berkas.
     */
    public function test_salinan_bisa_dibuka_dan_skemanya_utuh(): void
    {
        $asal = storage_path('app/uji-backup-asal.sqlite');
        $salinan = storage_path('app/uji-backup-salinan.sqlite');

        File::delete([$asal, $salinan]);
        File::put($asal, '');

        $pdo = new \PDO('sqlite:'.$asal);
        $pdo->exec('CREATE TABLE employees (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO employees (name) VALUES ('Rifqi')");
        $pdo->exec('VACUUM INTO '.$pdo->quote($salinan));

        $this->assertFileExists($salinan);

        $baca = new \PDO('sqlite:'.$salinan);
        $nama = $baca->query('SELECT name FROM employees LIMIT 1')->fetchColumn();

        $this->assertSame('Rifqi', $nama);

        File::delete([$asal, $salinan]);
    }

    /** Berkas contoh env harus memuat semua kunci yang dibaca aplikasi. */
    public function test_env_example_memuat_kunci_penting(): void
    {
        $isi = File::get(base_path('.env.example'));

        foreach ([
            'FINGERSPOT_API_TOKEN',
            'FINGERSPOT_CLOUD_ID',
            'FINGERSPOT_WEBHOOK_SECRET',
            'WHATSAPP_DRIVER',
            'DB_FOREIGN_KEYS',
        ] as $kunci) {
            $this->assertStringContainsString($kunci, $isi, "{$kunci} belum ada di .env.example");
        }
    }
}
