<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Division;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Requests\RequestService;
use App\Services\Roster\RosterService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pengganti yang bersedia bukan cuma catatan — begitu cuti/izin/sakitnya
 * disetujui, dia benar-benar menempati shift yang ditinggalkan, supaya dia
 * juga kena aturan Alpha yang sama kalau ternyata tidak masuk. Sebelum ini
 * "Pengganti" cuma syarat administratif (harus klik Bersedia dulu) tanpa
 * pernah menyentuh roster sama sekali — orang yang sudah setuju bantu
 * kehilangan shiftnya begitu saja di rekap.
 */
class LeaveSubstituteRosterTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected RequestService $service;

    protected RosterService $roster;

    protected LeaveType $sakit;

    protected Division $barista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-10 09:00:00', 'Asia/Jakarta'));

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->service = app(RequestService::class);
        $this->roster = app(RosterService::class);
        $this->sakit = LeaveType::where('code', 'sakit')->firstOrFail();
        $this->barista = Division::where('code', 'barista')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function karyawan(string $nama): Employee
    {
        return Employee::factory()->create(['branch_id' => Branch::current()->id, 'name' => $nama]);
    }

    protected function ajukanDanSetujui(Employee $pengaju, Carbon $tanggal, Employee $pengganti): void
    {
        $this->actingAs($this->admin);

        $request = $this->service->submitLeave($pengaju, [
            'leave_type_id' => $this->sakit->id,
            'start_date' => $tanggal->toDateString(),
            'end_date' => $tanggal->toDateString(),
            'reason' => 'Demam tinggi',
            'substitute_employee_id' => $pengganti->id,
        ]);

        $this->service->peerRespond($request->fresh(), $pengganti, true, 'Bersedia');
        $this->service->approve($request->fresh(), $this->admin, 'Disetujui');
    }

    public function test_pengganti_menempati_shift_yang_ditinggalkan(): void
    {
        $tanggal = Carbon::parse('2026-08-15', 'Asia/Jakarta');
        $pagi = Shift::where('code', 'pagi')->firstOrFail();

        $dava = $this->karyawan('Dava');
        $nuryati = $this->karyawan('Nuryati');

        $jadwalDava = $this->roster->assign(
            $this->roster->findOrCreate(2026, 8), $dava, $tanggal, $pagi->id, $this->barista->id,
        );

        $this->ajukanDanSetujui($dava, $tanggal, $nuryati);

        // Dava sendiri jadi libur, bukan hilang begitu saja.
        $this->assertSame('leave', $jadwalDava->fresh()->status->value);

        // Nuryati sekarang benar-benar terjadwal shift & divisi yang sama.
        $baris = RosterAssignment::where('employee_id', $nuryati->id)
            ->whereDate('work_date', $tanggal)->sole();

        $this->assertSame($pagi->id, $baris->shift_id);
        $this->assertSame($this->barista->id, $baris->division_id);
        $this->assertSame('leave', $baris->source);
        $this->assertTrue($baris->status->isWorking());
    }

    /** Yang cuti kebagian dobel shift hari itu — penggantinya menutup semuanya. */
    public function test_pengganti_menutup_dobel_shift(): void
    {
        $tanggal = Carbon::parse('2026-08-15', 'Asia/Jakarta');
        $pagi = Shift::where('code', 'pagi')->firstOrFail();
        $malam = Shift::where('code', 'malam')->firstOrFail();

        $dava = $this->karyawan('Dava Dobel');
        $nuryati = $this->karyawan('Nuryati');

        // Dobel shift dibuat lewat create() langsung, bukan assign() dua
        // kali — assign() kedua akan MEMINDAHKAN shift Dava (perilaku yang
        // benar untuk mengganti jadwal), bukan menambah baris kedua. Dobel
        // shift asli di produksi lahir dari jalur lain (pengambilalihan
        // lewat tukar shift), bukan dari assign() dipanggil berulang.
        $rosterAgustus = $this->roster->assign($this->roster->findOrCreate(2026, 8), $dava, $tanggal, $pagi->id);
        RosterAssignment::create([
            'roster_id' => $rosterAgustus->roster_id,
            'employee_id' => $dava->id,
            'work_date' => $tanggal,
            'shift_id' => $malam->id,
            'status' => 'scheduled',
            'source' => 'manual',
        ]);

        $this->ajukanDanSetujui($dava, $tanggal, $nuryati);

        $shiftNuryati = RosterAssignment::where('employee_id', $nuryati->id)
            ->whereDate('work_date', $tanggal)
            ->pluck('shift_id')->sort()->values();

        $this->assertSame([$pagi->id, $malam->id], $shiftNuryati->sort()->values()->all());
    }

    /** Yang cuti memang tidak ada jadwal hari itu — tidak ada apa pun untuk ditutup. */
    public function test_tanpa_jadwal_asli_pengganti_tidak_dapat_baris_baru(): void
    {
        $tanggal = Carbon::parse('2026-08-15', 'Asia/Jakarta');

        $dava = $this->karyawan('Dava Libur');
        $nuryati = $this->karyawan('Nuryati');

        $this->ajukanDanSetujui($dava, $tanggal, $nuryati);

        $this->assertSame(0, RosterAssignment::where('employee_id', $nuryati->id)
            ->whereDate('work_date', $tanggal)->count());
    }
}
