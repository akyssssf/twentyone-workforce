<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\ManualPayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\Payroll\PayrollPeriodFactory;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** `bonus:catat` — bonus manual untuk lembur yang tanggalnya di luar periode. */
class CatatBonusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'Asia/Jakarta'));
        User::factory()->create(['role' => UserRole::Admin]);
        Employee::factory()->create(['branch_id' => Branch::current()->id, 'name' => 'Amal Uji', 'pin_device' => '6']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_bonus_tersimpan_di_periode_yang_diminta(): void
    {
        $this->assertSame(0, Artisan::call('bonus:catat', [
            'pin' => '6', 'jumlah' => '123400', '--bulan' => '2026-10',
            '--alasan' => 'Lembur full shift 4 dan 5 September',
        ]));

        $bonus = ManualPayrollEntry::sole();
        $this->assertSame(123_400, (int) $bonus->amount);
        $this->assertSame('bonus', $bonus->entry_type);
        $this->assertSame('2026-10', PayrollPeriod::find($bonus->payroll_period_id)->code);
    }

    public function test_perintah_yang_sama_dua_kali_tidak_menggandakan(): void
    {
        $args = ['pin' => '6', 'jumlah' => '50000', '--bulan' => '2026-10', '--alasan' => 'Lembur susulan'];

        $this->assertSame(0, Artisan::call('bonus:catat', $args));
        $this->assertSame(1, Artisan::call('bonus:catat', $args));

        $this->assertSame(1, ManualPayrollEntry::count());
    }

    public function test_alasan_wajib_dan_dry_run_tidak_menyimpan(): void
    {
        $this->assertSame(1, Artisan::call('bonus:catat', ['pin' => '6', 'jumlah' => '50000']));
        $this->assertSame(0, Artisan::call('bonus:catat', ['pin' => '6', 'jumlah' => '50000', '--alasan' => 'Lembur susulan', '--dry-run' => true]));

        $this->assertSame(0, ManualPayrollEntry::count());
    }

    public function test_periode_terkunci_ditolak(): void
    {
        $periode = app(PayrollPeriodFactory::class)->forMonth(2026, 9);
        $periode->update(['status' => 'locked']);

        $this->assertSame(1, Artisan::call('bonus:catat', [
            'pin' => '6', 'jumlah' => '50000', '--bulan' => '2026-09', '--alasan' => 'Lembur susulan',
        ]));
        $this->assertSame(0, ManualPayrollEntry::count());
    }
}
