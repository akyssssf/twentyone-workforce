<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Models\Branch;
use App\Models\Division;
use App\Models\Employee;
use App\Models\RosterAssignment;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WaiterRosterRotationTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $farrel;

    protected Employee $dava;

    protected Employee $nur;

    protected Employee $amal;

    protected Shift $pagi;

    protected Shift $malam;

    protected function setUp(): void
    {
        parent::setUp();

        Branch::firstOrCreate(['code' => 'main'], ['name' => '21 Kafe']);

        $this->pagi = Shift::firstOrCreate(
            ['code' => 'pagi'],
            [
                'name' => 'Shift Pagi',
                'start_time' => '08:00:00',
                'end_time' => '18:00:00',
                'crosses_midnight' => false,
                'break_minutes' => 60,
                'is_break_paid' => true,
                'window_before_hours' => 4,
                'window_after_hours' => 4,
                'is_active' => true,
            ]
        );

        $this->malam = Shift::firstOrCreate(
            ['code' => 'malam'],
            [
                'name' => 'Shift Malam',
                'start_time' => '14:00:00',
                'end_time' => '01:00:00',
                'crosses_midnight' => true,
                'break_minutes' => 60,
                'is_break_paid' => true,
                'window_before_hours' => 4,
                'window_after_hours' => 4,
                'is_active' => true,
            ]
        );

        Division::firstOrCreate(['code' => 'waiter'], ['name' => 'Waiters', 'color' => '#10b981', 'sort_order' => 4]);

        $this->farrel = Employee::factory()->create(['name' => 'Farrel Daffa', 'pin_device' => '3']);
        $this->dava = Employee::factory()->create(['name' => 'Dava Erik Prasetiyo', 'pin_device' => '2']);
        $this->nur = Employee::factory()->create(['name' => 'Nurdiansyah', 'pin_device' => '8']);
        $this->amal = Employee::factory()->create(['name' => 'Muhammad Julian Ikhlusul Amal', 'pin_device' => '6']);
    }

    public function test_apply_waiters_roster_rotasi_4_minggu(): void
    {
        $this->artisan('roster:apply-waiters --from=2026-08-17 --to=2026-09-30')
            ->assertSuccessful();

        $middle = Shift::where('code', 'middle')->firstOrFail();

        // Minggu 1, Senin 17 Agustus 2026: Waye(Pagi), Dafa(Malam), Nur(Malam), Amal(Off)
        $this->assertShift($this->farrel, '2026-08-17', $this->pagi->id, AssignmentStatus::Scheduled);
        $this->assertShift($this->dava, '2026-08-17', $this->malam->id, AssignmentStatus::Scheduled);
        $this->assertShift($this->nur, '2026-08-17', $this->malam->id, AssignmentStatus::Scheduled);
        $this->assertShift($this->amal, '2026-08-17', null, AssignmentStatus::Off);

        // Minggu 1, Jumat 21 Agustus 2026: Amal(Pagi), Dafa(Middle), Nur(Malam), Waye(Malam)
        $this->assertShift($this->amal, '2026-08-21', $this->pagi->id, AssignmentStatus::Scheduled);
        $this->assertShift($this->dava, '2026-08-21', $middle->id, AssignmentStatus::Scheduled);
        $this->assertShift($this->nur, '2026-08-21', $this->malam->id, AssignmentStatus::Scheduled);
        $this->assertShift($this->farrel, '2026-08-21', $this->malam->id, AssignmentStatus::Scheduled);

        // Minggu 2, Jumat 28 Agustus 2026: Dafa(Pagi), Waye(Middle), Amal(Malam), Nur(Malam)
        $this->assertShift($this->dava, '2026-08-28', $this->pagi->id, AssignmentStatus::Scheduled);
        $this->assertShift($this->farrel, '2026-08-28', $middle->id, AssignmentStatus::Scheduled);
        $this->assertShift($this->amal, '2026-08-28', $this->malam->id, AssignmentStatus::Scheduled);
        $this->assertShift($this->nur, '2026-08-28', $this->malam->id, AssignmentStatus::Scheduled);

        // Minggu 3, Jumat 4 September 2026: Waye(Pagi), Nur(Middle), Dafa(Malam), Amal(Malam)
        $this->assertShift($this->farrel, '2026-09-04', $this->pagi->id, AssignmentStatus::Scheduled);
        $this->assertShift($this->nur, '2026-09-04', $middle->id, AssignmentStatus::Scheduled);
        $this->assertShift($this->dava, '2026-09-04', $this->malam->id, AssignmentStatus::Scheduled);
        $this->assertShift($this->amal, '2026-09-04', $this->malam->id, AssignmentStatus::Scheduled);

        // Minggu 4, Jumat 11 September 2026: Dafa(Pagi), Nur(Middle), Waye(Malam), Amal(Malam)
        $this->assertShift($this->dava, '2026-09-11', $this->pagi->id, AssignmentStatus::Scheduled);
        $this->assertShift($this->nur, '2026-09-11', $middle->id, AssignmentStatus::Scheduled);
        $this->assertShift($this->farrel, '2026-09-11', $this->malam->id, AssignmentStatus::Scheduled);
        $this->assertShift($this->amal, '2026-09-11', $this->malam->id, AssignmentStatus::Scheduled);

        // Perulangan Minggu 1 di 14 September 2026: Waye(Pagi), Dafa(Malam), Nur(Malam), Amal(Off)
        $this->assertShift($this->farrel, '2026-09-14', $this->pagi->id, AssignmentStatus::Scheduled);
        $this->assertShift($this->dava, '2026-09-14', $this->malam->id, AssignmentStatus::Scheduled);
        $this->assertShift($this->nur, '2026-09-14', $this->malam->id, AssignmentStatus::Scheduled);
        $this->assertShift($this->amal, '2026-09-14', null, AssignmentStatus::Off);
    }

    protected function assertShift(Employee $emp, string $date, ?int $shiftId, AssignmentStatus $status): void
    {
        $assignment = RosterAssignment::where('employee_id', $emp->id)
            ->whereDate('work_date', Carbon::parse($date))
            ->first();

        $this->assertNotNull($assignment, "Assignment untuk {$emp->name} pada {$date} tidak ditemukan.");
        $this->assertSame($shiftId, $assignment->shift_id);
        $this->assertSame($status, $assignment->status);
    }
}
