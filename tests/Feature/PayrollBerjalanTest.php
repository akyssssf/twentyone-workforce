<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\Payroll\PayrollPeriodFactory;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `payroll:hitung` — estimasi payroll periode berjalan, manual atau oleh cron.
 */
class PayrollBerjalanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);

        // 22 September ada di periode 2026-10 (21 Sep–20 Okt) yang masih berjalan.
        Carbon::setTestNow(Carbon::parse('2026-09-22 07:00:00', 'Asia/Jakarta'));

        $budi = Employee::factory()->create(['branch_id' => Branch::current()->id, 'name' => 'Budi Uji', 'pin_device' => '31']);
        $budi->salaries()->create([
            'salary_component_id' => SalaryComponent::where('code', 'gaji_pokok')->value('id'),
            'amount' => 3_000_000,
            'effective_from' => '2026-08-01',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_tanpa_argumen_menghitung_periode_yang_mencakup_hari_ini(): void
    {
        $this->assertSame(0, Artisan::call('payroll:hitung'));

        $keluaran = Artisan::output();
        $this->assertStringContainsString('Payroll 2026-10', $keluaran);
        $this->assertStringContainsString('ESTIMASI', $keluaran);
        $this->assertStringContainsString('Budi Uji', $keluaran);

        $run = PayrollPeriod::where('code', '2026-10')->firstOrFail()->activeRun();
        $this->assertSame('manual', $run->trigger);
        $this->assertSame(1, (int) $run->employee_count);
    }

    /** Estimasi harian tidak menumpuk: run otomatis lama dibersihkan, run manual tetap. */
    public function test_run_otomatis_lama_dibersihkan_run_manual_dijaga(): void
    {
        Artisan::call('payroll:hitung 2026-10');
        Artisan::call('payroll:hitung 2026-10 --otomatis');
        Artisan::call('payroll:hitung 2026-10 --otomatis');
        Artisan::call('payroll:hitung 2026-10 --otomatis');

        $periode = PayrollPeriod::where('code', '2026-10')->firstOrFail();

        $this->assertSame(
            ['manual' => 1, 'otomatis' => 1],
            $periode->runs()->get()->countBy('trigger')->sortKeys()->all(),
        );
        $this->assertSame(4, (int) $periode->activeRun()->version, 'nomor versi tetap berjalan');
        $this->assertSame(2, Payslip::count(), 'slip run otomatis lama ikut terhapus');
    }

    public function test_periode_yang_disetujui_dilewati_cron_tapi_ditolak_manual(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Artisan::call('payroll:hitung 2026-10');
        app(PayrollPeriodFactory::class)->approve(PayrollPeriod::where('code', '2026-10')->firstOrFail());

        $this->assertSame(0, Artisan::call('payroll:hitung 2026-10 --otomatis'));
        $this->assertStringContainsString('tidak dihitung ulang', Artisan::output());

        $this->assertSame(1, Artisan::call('payroll:hitung 2026-10'));
        $this->assertSame(1, PayrollRun::count());
    }

    public function test_halaman_periode_berjalan_menandai_estimasi(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        // Daftar periode membuat periode berjalan sendiri.
        $this->get(route('manajer.payroll.index'))->assertOk()->assertSee('2026-10');

        $periode = PayrollPeriod::where('code', '2026-10')->firstOrFail();

        $this->get(route('manajer.payroll.show', $periode))
            ->assertOk()
            ->assertSee('Estimasi berjalan');
    }

    /** Label periode selalu pola 21–20 walau batas hitungnya digeser. */
    public function test_label_tetap_pola_baku_walau_batas_hitung_bergeser(): void
    {
        $periode = app(PayrollPeriodFactory::class)->forMonth(2026, 10);
        $periode->update(['start_date' => '2026-09-22']);

        $this->assertSame('21 Sep – 20 Okt 2026', $periode->fresh()->label());
        $this->assertSame('22 Sep – 20 Okt 2026', $periode->fresh()->rentangHitung());
    }
}
