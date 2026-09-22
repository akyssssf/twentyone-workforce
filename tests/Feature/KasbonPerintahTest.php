<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashAdvance;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `kasbon:catat`, `kasbon:daftar`, `kasbon:batal`.
 */
class KasbonPerintahTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $nur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-22 10:00:00', 'Asia/Jakarta'));

        $this->nur = Employee::factory()->create(['branch_id' => Branch::current()->id, 'name' => 'Nur Uji', 'pin_device' => '19']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** 22 September ada di periode 2026-10 (21 Sep–20 Okt): itu bawaan bulan potongnya. */
    public function test_catat_memakai_periode_yang_mencakup_hari_ini(): void
    {
        $this->artisan('kasbon:catat --data=19:500000 --keterangan="bayar kos"')->assertSuccessful();

        $kasbon = CashAdvance::sole();
        $this->assertSame($this->nur->id, $kasbon->employee_id);
        $this->assertSame(500_000, (int) $kasbon->amount);
        $this->assertSame('disbursed', $kasbon->status);
        $this->assertSame('bayar kos', $kasbon->reason);
        $this->assertSame('2026-09-22', $kasbon->disbursed_at->toDateString());

        $cicilan = $kasbon->installments;
        $this->assertCount(1, $cicilan);
        $this->assertSame('2026-10', $cicilan[0]->period->code);
        $this->assertSame('scheduled', $cicilan[0]->status);
    }

    public function test_cicilan_dibagi_rata_sisa_di_cicilan_pertama(): void
    {
        $this->artisan('kasbon:catat --data=19:1000000 --bulan=2026-10 --cicilan=3')->assertSuccessful();

        $cicilan = CashAdvance::sole()->installments;
        $this->assertSame([333_334, 333_333, 333_333], $cicilan->pluck('amount')->map(fn ($a) => (int) $a)->all());
        $this->assertSame(['2026-10', '2026-11', '2026-12'], $cicilan->map(fn ($c) => $c->period->code)->all());
        $this->assertTrue(PayrollPeriod::where('code', '2026-12')->exists(), 'periode ke depan dibuat otomatis');
    }

    public function test_pin_salah_membatalkan_semuanya(): void
    {
        $this->artisan('kasbon:catat --data=19:500000 --data=999:100000')->assertFailed();

        $this->assertSame(0, CashAdvance::count());
    }

    public function test_dry_run_tidak_menyimpan(): void
    {
        $this->assertSame(0, Artisan::call('kasbon:catat --data=19:500000 --dry-run'));

        $this->assertStringContainsString('DRY RUN', Artisan::output());
        $this->assertSame(0, CashAdvance::count());
    }

    public function test_daftar_dan_batal(): void
    {
        $this->artisan('kasbon:catat --data=19:600000 --bulan=2026-10 --cicilan=2')->assertSuccessful();

        $this->assertSame(0, Artisan::call('kasbon:daftar'));
        $keluaran = Artisan::output();
        $this->assertStringContainsString('Nur Uji', $keluaran);
        $this->assertStringContainsString('2026-10 300.000 belum', $keluaran);
        $this->assertStringContainsString('2026-11 300.000 belum', $keluaran);

        $id = CashAdvance::sole()->id;
        $this->artisan("kasbon:batal {$id} --ya")->assertSuccessful();

        $kasbon = CashAdvance::sole();
        $this->assertSame('cancelled', $kasbon->status);
        $this->assertSame(['skipped', 'skipped'], $kasbon->installments->pluck('status')->all());

        $this->assertSame(0, Artisan::call('kasbon:daftar'));
        $this->assertStringContainsString('Tidak ada kasbon', Artisan::output());
    }
}
