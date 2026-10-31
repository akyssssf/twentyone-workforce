<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\ManualPayrollEntry;
use App\Models\OvertimeRecord;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** `lembur:bonus-umum` — lembur General Cleaning dibayar sebagai bonus di periode yang sudah berjalan. */
class BonusLemburUmumTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $amal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-31 10:00:00', 'Asia/Jakarta'));
        User::factory()->create(['role' => UserRole::Admin]);

        $this->amal = Employee::factory()->create(['branch_id' => Branch::current()->id, 'name' => 'Amal Uji', 'pin_device' => '6']);
        $this->amal->salaries()->create([
            'salary_component_id' => SalaryComponent::where('code', 'gaji_pokok')->value('id'),
            'amount' => 3_000_000,
            'effective_from' => '2026-08-01',
        ]);

        OvertimeRecord::create([
            'employee_id' => $this->amal->id, 'work_date' => Carbon::parse('2026-10-27'), 'multiplier' => 2,
            'actual_minutes' => 294, 'approved_minutes' => 294, 'payable_minutes' => 294,
            'status' => 'confirmed', 'activated_at' => now(), 'confirmed_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function slip(string $periode): ?Payslip
    {
        return PayrollPeriod::where('code', $periode)->firstOrFail()->activeRun()?->payslips()->with('items')->first();
    }

    public function test_pratinjau_tidak_menyimpan(): void
    {
        Artisan::call('payroll:hitung', ['bulan' => '2026-10']);

        $this->assertSame(0, Artisan::call('lembur:bonus-umum', ['tanggal' => '2026-10-27', '--bulan' => '2026-10']));

        $this->assertSame(0, ManualPayrollEntry::count());
        $this->assertNull(OvertimeRecord::first()->paid_in_period);
    }

    public function test_bonus_masuk_slip_dan_tidak_dibayar_lagi_di_periode_berikutnya(): void
    {
        Artisan::call('payroll:hitung', ['bulan' => '2026-10']);

        $this->assertSame(0, Artisan::call('lembur:bonus-umum', ['tanggal' => '2026-10-27', '--bulan' => '2026-10', '--simpan' => true]));

        $bonus = ManualPayrollEntry::sole();
        // gaji 3.000.000 ÷ 26 hari ÷ 10 jam = 11.500/jam; 294 mnt = 4,9 jam; ×1 tier ×2.
        $this->assertGreaterThan(0, (int) $bonus->amount);
        $this->assertStringContainsString('General Cleaning 27 Okt 2026', $bonus->reason);
        $this->assertSame('2026-10', OvertimeRecord::first()->paid_in_period);

        Artisan::call('payroll:hitung', ['bulan' => '2026-10']);
        $item = $this->slip('2026-10')->items->firstWhere('source_id', $bonus->id);
        $this->assertSame((int) $bonus->amount, (int) $item->amount);

        // Payroll periode tanggal kerjanya tidak lagi membayar lembur yang sama.
        Artisan::call('payroll:hitung', ['bulan' => '2026-11']);
        $this->assertNull($this->slip('2026-11')->items->firstWhere('source_type', 'overtime'));
    }

    public function test_dijalankan_dua_kali_tidak_menggandakan(): void
    {
        Artisan::call('payroll:hitung', ['bulan' => '2026-10']);
        $args = ['tanggal' => '2026-10-27', '--bulan' => '2026-10', '--simpan' => true];

        Artisan::call('lembur:bonus-umum', $args);
        Artisan::call('lembur:bonus-umum', $args);

        $this->assertSame(1, ManualPayrollEntry::count());
    }

    public function test_periode_belum_dihitung_dilewati_dengan_petunjuk(): void
    {
        Artisan::call('lembur:bonus-umum', ['tanggal' => '2026-10-27', '--bulan' => '2026-10', '--simpan' => true]);

        $this->assertSame(0, ManualPayrollEntry::count());
        $this->assertStringContainsString('payroll:hitung', Artisan::output());
    }
}
