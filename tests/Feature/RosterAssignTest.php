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

    // -------------------------------------------------------- markLeave()

    /**
     * Kasus yang benar-benar terjadi di produksi: karyawan yang baru
     * mengambil-alih shift rekan lewat tukar (jadi punya DUA baris hari itu)
     * mengajukan sakit untuk tanggal yang sama. Sebelum ini, mass update di
     * markLeave() mencoba menjadikan shift_key KEDUA baris = 0 sekaligus —
     * baris kedua nabrak baris pertama yang barusan jadi 0, gagal dengan
     * error mentah dari database.
     */
    public function test_sakit_dengan_dua_baris_roster_diringkas_jadi_satu(): void
    {
        $pagi = Shift::factory()->create();
        $malam = Shift::factory()->malam()->create();
        $employee = Employee::factory()->create(['default_shift_id' => $pagi->id]);

        $roster = $this->service->findOrCreate(2026, 8);

        // Dua baris hari yang sama, seperti hasil mengambil-alih shift rekan.
        $this->service->assign($roster, $employee, $this->tanggal(), $pagi->id);
        RosterAssignment::create([
            'roster_id' => $roster->id,
            'employee_id' => $employee->id,
            'work_date' => $this->tanggal(),
            'shift_id' => $malam->id,
            'status' => 'scheduled',
            'source' => 'swap',
        ]);

        $this->assertSame(2, RosterAssignment::where('employee_id', $employee->id)
            ->whereDate('work_date', $this->tanggal())->count());

        $this->service->markLeave($employee, $this->tanggal(), requestId: 99);

        $baris = RosterAssignment::where('employee_id', $employee->id)
            ->whereDate('work_date', $this->tanggal())->get();

        $this->assertCount(1, $baris);
        $this->assertSame('leave', $baris->first()->status->value);
        $this->assertNull($baris->first()->shift_id);
        $this->assertSame(0, $baris->first()->shift_key);
    }

    public function test_sakit_tanpa_roster_sama_sekali_tetap_membuat_baris(): void
    {
        $employee = Employee::factory()->create();

        $this->service->markLeave($employee, $this->tanggal(), requestId: 5);

        $baris = RosterAssignment::where('employee_id', $employee->id)
            ->whereDate('work_date', $this->tanggal())->sole();

        $this->assertSame('leave', $baris->status->value);
        $this->assertSame(5, $baris->source_request_id);
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
