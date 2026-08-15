<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Models\Employee;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Services\Roster\RosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Mengubah jadwal seseorang harus MEMINDAHKAN shift-nya, bukan menambah baris
 * kedua. Ini pernah lolos ke produksi: shift_key ikut jadi kunci karena double
 * shift diizinkan, sehingga koreksi jadwal meninggalkan baris lama dan orang
 * yang sama muncul dua kali di rekap absensi.
 */
class RosterAssignTest extends TestCase
{
    use RefreshDatabase;

    protected RosterService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(RosterService::class);
        Carbon::setTestNow(Carbon::parse('2026-08-10 09:00:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function tanggal(): Carbon
    {
        return Carbon::parse('2026-08-15', 'Asia/Jakarta');
    }

    public function test_ganti_shift_tidak_meninggalkan_baris_lama(): void
    {
        $pagi = Shift::factory()->create();
        $malam = Shift::factory()->malam()->create();
        $employee = Employee::factory()->create(['default_shift_id' => $pagi->id]);

        $roster = $this->service->findOrCreate(2026, 8);

        $this->service->assign($roster, $employee, $this->tanggal(), $pagi->id);
        $this->service->assign($roster, $employee, $this->tanggal(), $malam->id);

        $baris = RosterAssignment::where('employee_id', $employee->id)
            ->whereDate('work_date', $this->tanggal())
            ->get();

        $this->assertCount(1, $baris);
        $this->assertSame($malam->id, $baris->first()->shift_id);
    }

    public function test_ganti_jadi_libur_juga_menghapus_baris_shift(): void
    {
        $pagi = Shift::factory()->create();
        $employee = Employee::factory()->create(['default_shift_id' => $pagi->id]);

        $roster = $this->service->findOrCreate(2026, 8);

        $this->service->assign($roster, $employee, $this->tanggal(), $pagi->id);
        $this->service->assign($roster, $employee, $this->tanggal(), null);

        $baris = RosterAssignment::where('employee_id', $employee->id)
            ->whereDate('work_date', $this->tanggal())
            ->get();

        $this->assertCount(1, $baris);
        $this->assertNull($baris->first()->shift_id);
        $this->assertSame(AssignmentStatus::Off, $baris->first()->status);
    }

    /**
     * Cuti yang sudah disetujui bukan sisa jadwal lama. Menghapusnya diam-diam
     * saat roster diperbaiki akan membatalkan keputusan manager tanpa jejak.
     */
    public function test_baris_dari_pengajuan_yang_disetujui_tidak_ikut_terhapus(): void
    {
        $pagi = Shift::factory()->create();
        $malam = Shift::factory()->malam()->create();
        $employee = Employee::factory()->create(['default_shift_id' => $pagi->id]);

        $roster = $this->service->findOrCreate(2026, 8);

        $this->service->assign($roster, $employee, $this->tanggal(), $pagi->id, null, 'leave');
        $this->service->assign($roster, $employee, $this->tanggal(), $malam->id);

        $baris = RosterAssignment::where('employee_id', $employee->id)
            ->whereDate('work_date', $this->tanggal())
            ->get();

        $this->assertCount(2, $baris);
        $this->assertTrue($baris->contains(fn ($a) => $a->source === 'leave'));
    }

    public function test_menetapkan_shift_yang_sama_dua_kali_tetap_satu_baris(): void
    {
        $pagi = Shift::factory()->create();
        $employee = Employee::factory()->create(['default_shift_id' => $pagi->id]);

        $roster = $this->service->findOrCreate(2026, 8);

        $this->service->assign($roster, $employee, $this->tanggal(), $pagi->id);
        $this->service->assign($roster, $employee, $this->tanggal(), $pagi->id);

        $this->assertSame(1, RosterAssignment::where('employee_id', $employee->id)
            ->whereDate('work_date', $this->tanggal())
            ->count());
    }
}
