<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Shift;
use App\Services\Attendance\AttendanceComputer;
use App\Services\Roster\RosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Libur fleksibel: roster penuh, hari tanpa scan = Libur (bukan Alpha).
 */
class LiburFleksibelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-12 12:00:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function statusHari(bool $fleksibel): string
    {
        $shift = Shift::factory()->create();
        $e = Employee::factory()->create(['pin_device' => '23', 'default_shift_id' => $shift->id, 'flexible_off' => $fleksibel]);

        $service = app(RosterService::class);
        $service->assign($service->findOrCreate(2026, 11), $e, Carbon::parse('2026-11-10'), $shift->id);

        return app(AttendanceComputer::class)
            ->computeEmployee($e, Carbon::parse('2026-11-10', 'Asia/Jakarta'))
            ->first()->status->value;
    }

    public function test_tanpa_scan_di_hari_terjadwal_biasanya_alpha(): void
    {
        $this->assertSame('alpha', $this->statusHari(false));
    }

    public function test_tanpa_scan_di_hari_terjadwal_libur_fleksibel_jadi_libur(): void
    {
        $this->assertSame('libur', $this->statusHari(true));
    }

    public function test_employee_edit_menyalakan_dan_mematikan_libur_fleksibel(): void
    {
        $e = Employee::factory()->create(['pin_device' => '23']);

        Artisan::call('employee:edit', ['pin' => '23', '--libur-fleksibel' => 'ya']);
        $this->assertTrue($e->fresh()->flexible_off);

        Artisan::call('employee:edit', ['pin' => '23', '--libur-fleksibel' => 'tidak']);
        $this->assertFalse($e->fresh()->flexible_off);
    }
}
