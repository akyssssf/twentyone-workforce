<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `employee:reset-sandi` — sandi acak sekali tampil untuk yang lupa.
 *
 * Jalurnya sama dengan tombol di panel admin. Yang dijaga: sandi lama benar-
 * benar tidak berlaku lagi, dan orangnya dipaksa mengganti saat login pertama
 * — sandi buatan admin bukan rahasia milik orangnya sampai dia menggantinya.
 */
class ResetSandiTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $dava;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);

        $this->dava = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Dava Uji',
            'pin_device' => '2',
            'default_shift_id' => Shift::where('code', 'pagi')->firstOrFail()->id,
        ]);
    }

    public function test_sandi_lama_tidak_berlaku_dan_wajib_ganti(): void
    {
        $akun = User::factory()->create([
            'username' => 'dava',
            'employee_id' => $this->dava->id,
            'password' => 'sandi-lama',
            'must_change_password' => false,
        ]);

        $this->artisan('employee:reset-sandi 2')->assertSuccessful();

        $akun->refresh();

        $this->assertFalse(Hash::check('sandi-lama', $akun->password));
        $this->assertTrue($akun->must_change_password);
    }

    public function test_tanpa_akun_ditolak_dan_diarahkan_membuat(): void
    {
        $this->artisan('employee:reset-sandi 2')
            ->assertFailed()
            ->expectsOutputToContain('belum punya akun');
    }

    public function test_pin_tidak_dikenal_ditolak(): void
    {
        $this->artisan('employee:reset-sandi 999')->assertFailed();
    }
}
