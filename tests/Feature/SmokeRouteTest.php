<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\Request as PengajuanModel;
use App\Models\Roster;
use App\Models\User;
use App\Services\Payroll\PayrollGenerator;
use App\Services\Payroll\PayrollPeriodFactory;
use App\Services\Requests\RequestService;
use App\Services\Roster\RosterService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Setiap halaman harus benar-benar terbuka.
 *
 * Uji ini sengaja dangkal tapi luas: ia tidak memeriksa isi halaman, hanya
 * memastikan tidak ada rute yang mati karena view yang salah nama, variabel
 * yang lupa dikirim controller, atau relasi yang berganti nama. Kesalahan
 * seperti itu tidak pernah tertangkap unit test, tapi langsung terlihat
 * penggunanya sebagai layar error.
 */
class SmokeRouteTest extends TestCase
{
    use RefreshDatabase;

    protected User $manajer;

    protected User $karyawan;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);

        $this->manajer = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $this->employee = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Karyawan Uji',
        ]);

        $this->karyawan = User::factory()->create([
            'role' => UserRole::Karyawan,
            'employee_id' => $this->employee->id,
            'is_active' => true,
            'password' => Hash::make('rahasia'),
        ]);
    }

    public function test_semua_halaman_manajer_terbuka(): void
    {
        $roster = app(RosterService::class)->findOrCreate((int) now()->year, (int) now()->month);
        app(RosterService::class)->generate($roster);

        $period = app(PayrollPeriodFactory::class)->forMonth((int) now()->year, (int) now()->month);

        $this->actingAs($this->manajer);

        $rute = [
            '/dashboard',
            '/aktivitas',
            '/laporan',
            '/manajer/roster',
            "/manajer/roster/{$roster->id}",
            '/manajer/pengajuan',
            '/manajer/lembur',
            '/manajer/payroll',
            "/manajer/payroll/{$period->id}",
            '/manajer/karyawan',
            "/manajer/karyawan/{$this->employee->id}",
            '/manajer/aturan',
            '/manajer/audit',
        ];

        foreach ($rute as $url) {
            $this->get($url)->assertOk("Halaman {$url} gagal terbuka.");
        }
    }

    public function test_semua_halaman_karyawan_terbuka(): void
    {
        $this->actingAs($this->karyawan);

        $rute = [
            '/karyawan',
            '/karyawan/jadwal',
            '/karyawan/absensi',
            '/karyawan/pengajuan',
            '/karyawan/pengajuan/baru/leave',
            '/karyawan/pengajuan/baru/swap',
            '/karyawan/pengajuan/baru/correction',
            '/karyawan/slip',
        ];

        foreach ($rute as $url) {
            $this->get($url)->assertOk("Halaman {$url} gagal terbuka.");
        }
    }

    /**
     * Lembur tidak bisa diajukan sendiri oleh karyawan.
     *
     * Sejak kebijakan kode rahasia, lembur selalu berawal dari penunjukan
     * admin. Formulir pengajuannya sengaja ditutup supaya tidak ada jalur
     * kedua menuju lembur yang dibayar tanpa kode.
     */
    public function test_karyawan_tidak_bisa_mengajukan_lembur_sendiri(): void
    {
        $this->actingAs($this->karyawan)
            ->get('/karyawan/pengajuan/baru/overtime')
            ->assertNotFound();

        $this->actingAs($this->karyawan)
            ->post('/karyawan/pengajuan/baru/overtime', [
                'work_date' => now()->addDay()->toDateString(),
                'planned_start' => '18:00',
                'planned_end' => '20:00',
                'reason' => 'Mau lembur sendiri',
            ])
            ->assertNotFound();
    }

    public function test_karyawan_tidak_bisa_masuk_area_manajer(): void
    {
        $this->actingAs($this->karyawan)
            ->get('/manajer/payroll')
            ->assertRedirect(route('karyawan.beranda'));

        $this->actingAs($this->karyawan)
            ->get('/dashboard')
            ->assertRedirect(route('karyawan.beranda'));
    }

    public function test_manajer_yang_bukan_karyawan_diarahkan_keluar_dari_portal(): void
    {
        $this->actingAs($this->manajer)
            ->get('/karyawan/slip')
            ->assertRedirect(route('dashboard'));
    }

    /**
     * Otorisasi harus PER BARIS, bukan sekadar menyembunyikan tautan.
     *
     * Ini penjaga paling penting di seluruh aplikasi: slip gaji orang lain
     * tidak boleh terbuka hanya karena URL-nya ditebak.
     */
    public function test_karyawan_tidak_bisa_membuka_slip_gaji_orang_lain(): void
    {
        $lain = Employee::factory()->create(['branch_id' => Branch::current()->id]);

        $period = app(PayrollPeriodFactory::class)->forMonth((int) now()->year, (int) now()->month);
        $run = app(PayrollGenerator::class)->generate($period);

        $slipOrangLain = Payslip::where('payroll_run_id', $run->id)
            ->where('employee_id', $lain->id)
            ->sole();

        $slipOrangLain->update(['status' => 'published', 'published_at' => now()]);

        $this->actingAs($this->karyawan)
            ->get("/karyawan/slip/{$slipOrangLain->id}")
            ->assertForbidden();
    }

    /** Slip yang belum disetujui belum boleh terlihat karyawan. */
    public function test_slip_yang_belum_terbit_tidak_bisa_dibuka(): void
    {
        $period = app(PayrollPeriodFactory::class)->forMonth((int) now()->year, (int) now()->month);
        $run = app(PayrollGenerator::class)->generate($period);

        $slipSaya = Payslip::where('payroll_run_id', $run->id)
            ->where('employee_id', $this->employee->id)
            ->sole();

        $this->assertSame('draft', $slipSaya->status);

        $this->actingAs($this->karyawan)
            ->get("/karyawan/slip/{$slipSaya->id}")
            ->assertForbidden();
    }

    public function test_pengajuan_bisa_dibuka_pengaju_dan_manajer(): void
    {
        $rekan = Employee::factory()->create(['branch_id' => Branch::current()->id]);

        $pengajuan = app(RequestService::class)->submitCorrection($this->employee, [
            'work_date' => now()->subDay()->toDateString(),
            'correction_type' => 'lupa_pulang',
            'reason' => 'Mesin tidak merespons saat pulang',
            'substitute_employee_id' => $rekan->id,
        ]);

        $this->actingAs($this->karyawan)
            ->get("/karyawan/pengajuan/{$pengajuan->id}")
            ->assertOk();

        $this->actingAs($this->manajer)
            ->get("/manajer/pengajuan/{$pengajuan->id}")
            ->assertOk();
    }

    /**
     * Pengajuan yang sama sekali tidak menyangkut saya tetap tertutup.
     *
     * Penggantinya sengaja orang ketiga: pengganti memang BERHAK melihat,
     * karena dia yang harus memutuskan bersedia atau tidak.
     */
    public function test_pengajuan_orang_lain_tidak_bisa_dibuka_karyawan(): void
    {
        $lain = Employee::factory()->create(['branch_id' => Branch::current()->id]);
        $orangKetiga = Employee::factory()->create(['branch_id' => Branch::current()->id]);

        $pengajuan = app(RequestService::class)->submitCorrection($lain, [
            'work_date' => now()->subDay()->toDateString(),
            'correction_type' => 'lupa_masuk',
            'reason' => 'Antre panjang di mesin saat masuk',
            'substitute_employee_id' => $orangKetiga->id,
        ]);

        $this->actingAs($this->karyawan)
            ->get("/karyawan/pengajuan/{$pengajuan->id}")
            ->assertForbidden();
    }
}
