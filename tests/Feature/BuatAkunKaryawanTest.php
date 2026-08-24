<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `employee:akun` — buatkan akun login untuk karyawan yang belum punya.
 *
 * Celah yang ditutupnya: panel admin bisa mengganti nama panggilan dan
 * mengatur ulang sandi, tapi keduanya berhenti dengan "belum punya akun
 * login", dan user:add berbasis email serta tidak menautkan ke karyawan.
 * Karyawan baru karena itu tidak pernah bisa masuk.
 */
class BuatAkunKaryawanTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $indra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);

        $this->indra = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Indra Tri Setyo',
            'pin_device' => '23',
        ]);
    }

    public function test_akun_dibuat_dan_tertaut_ke_karyawan(): void
    {
        $this->artisan('employee:akun 23 --username=umin')->assertSuccessful();

        $user = User::where('username', 'umin')->sole();

        $this->assertSame($this->indra->id, $user->employee_id);
        $this->assertSame(UserRole::Karyawan, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertSame('Indra Tri Setyo', $user->name);
    }

    /** Sandi acak buatan admin belum jadi rahasia orangnya sampai dia ganti. */
    public function test_wajib_ganti_sandi_saat_login_pertama(): void
    {
        $this->artisan('employee:akun 23 --username=umin');

        $this->assertTrue(User::where('username', 'umin')->sole()->must_change_password);
    }

    /** Sandinya ditampilkan sekali, dan yang tersimpan cuma hash-nya. */
    public function test_sandi_tersimpan_sebagai_hash_dan_benar_benar_dipakai(): void
    {
        $this->artisan('employee:akun 23 --username=umin')->assertSuccessful();

        $user = User::where('username', 'umin')->sole();

        $this->assertNotSame('', $user->password);
        $this->assertTrue(str_starts_with($user->password, '$'));
        $this->assertFalse(Hash::check('umin', $user->password));
    }

    public function test_karyawan_yang_sudah_punya_akun_ditolak(): void
    {
        $this->artisan('employee:akun 23 --username=umin')->assertSuccessful();
        $this->artisan('employee:akun 23 --username=umin2')->assertFailed();

        $this->assertSame(1, User::where('employee_id', $this->indra->id)->count());
    }

    public function test_nama_panggilan_yang_sudah_dipakai_ditolak(): void
    {
        User::factory()->create(['username' => 'umin']);

        $this->artisan('employee:akun 23 --username=umin')->assertFailed();

        $this->assertNull($this->indra->fresh()->user);
    }

    /** Spasi dan huruf besar gagal diketik di layar ponsel — ditolak di depan. */
    public function test_nama_panggilan_ngawur_ditolak(): void
    {
        $this->artisan('employee:akun 23 --username="Umin Ganteng"')->assertFailed();
        $this->artisan('employee:akun 23 --username=um')->assertFailed();

        $this->assertNull($this->indra->fresh()->user);
    }

    public function test_pin_tidak_dikenal_ditolak(): void
    {
        $this->artisan('employee:akun 999 --username=umin')->assertFailed();
    }

    /** Email wajib unik di tabel users walaupun tidak pernah dipakai kirim apa pun. */
    public function test_nama_kembar_tidak_menabrak_email_unik(): void
    {
        $kembar = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Indra Tri Setyo',
            'pin_device' => '24',
        ]);

        $this->artisan('employee:akun 23 --username=umin')->assertSuccessful();
        $this->artisan('employee:akun 24 --username=umin2')->assertSuccessful();

        $this->assertSame(2, User::whereIn('employee_id', [$this->indra->id, $kembar->id])->count());
    }
}
