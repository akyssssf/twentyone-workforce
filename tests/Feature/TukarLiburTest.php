<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Request;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Requests\RequestService;
use App\Services\Roster\RosterService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * Tukar hari libur antara dua orang.
 *
 * Secara mekanis ini DUA kali tukar shift: isi baris kedua orang ditukar di
 * tanggal libur pengaju, lalu ditukar lagi di tanggal libur rekannya. Karena
 * itu dia menumpang mesin penukar yang sudah ada — yang menukar ISI baris,
 * bukan kepemilikannya, karena SQLite tidak menunda pengecekan constraint unik.
 *
 * Yang paling gampang lolos di sini adalah STATUS. Baris libur yang menerima
 * shift tetap berstatus Off kalau statusnya tidak ikut disesuaikan, dan
 * AttendanceComputer membaca status — bukan shift_id — untuk memutuskan hari
 * itu hari kerja atau bukan. Akibatnya orang yang benar-benar masuk tetap
 * tercatat Libur, tanpa error apa pun.
 */
class TukarLiburTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected RequestService $service;

    protected RosterService $roster;

    protected Shift $pagi;

    protected Employee $ani;

    protected Employee $budi;

    protected Carbon $liburAni;

    protected Carbon $liburBudi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-10 09:00:00', 'Asia/Jakarta'));

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->service = app(RequestService::class);
        $this->roster = app(RosterService::class);
        $this->pagi = Shift::where('code', 'pagi')->firstOrFail();

        $this->ani = $this->karyawan('Ani');
        $this->budi = $this->karyawan('Budi');

        $this->liburAni = Carbon::parse('2026-08-17', 'Asia/Jakarta');
        $this->liburBudi = Carbon::parse('2026-08-20', 'Asia/Jakarta');

        // Ani libur Senin & kerja Kamis; Budi kebalikannya.
        $this->jadwalkan($this->ani, $this->liburAni, null);
        $this->jadwalkan($this->ani, $this->liburBudi, $this->pagi->id);
        $this->jadwalkan($this->budi, $this->liburAni, $this->pagi->id);
        $this->jadwalkan($this->budi, $this->liburBudi, null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function karyawan(string $nama): Employee
    {
        return Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => $nama,
        ]);
    }

    protected function jadwalkan(Employee $employee, Carbon $tanggal, ?int $shiftId): RosterAssignment
    {
        return $this->roster->assign(
            $this->roster->findOrCreate((int) $tanggal->year, (int) $tanggal->month),
            $employee,
            $tanggal,
            $shiftId,
        );
    }

    protected function baris(Employee $employee, Carbon $tanggal): RosterAssignment
    {
        return RosterAssignment::where('employee_id', $employee->id)
            ->whereDate('work_date', $tanggal)
            ->sole();
    }

    protected function ajukan(?int $milikSaya = null, ?int $milikRekan = null): Request
    {
        $this->actingAs($this->admin);

        return $this->service->submitSwapOff($this->ani, [
            'requester_assignment_id' => $milikSaya ?? $this->baris($this->ani, $this->liburAni)->id,
            'partner_assignment_id' => $milikRekan ?? $this->baris($this->budi, $this->liburBudi)->id,
            'reason' => 'Ada acara keluarga hari Kamis',
        ]);
    }

    protected function ajukanDanSetujui(): void
    {
        $request = $this->ajukan();

        $this->service->peerRespond($request->fresh(), $this->budi, true, 'Bersedia');
        $this->service->approve($request->fresh(), $this->admin, 'Disetujui');
    }

    public function test_dua_orang_benar_benar_bertukar_hari_libur(): void
    {
        $this->ajukanDanSetujui();

        // Tanggal libur Ani: sekarang Ani yang masuk, Budi yang libur.
        $this->assertSame($this->pagi->id, $this->baris($this->ani, $this->liburAni)->shift_id);
        $this->assertNull($this->baris($this->budi, $this->liburAni)->shift_id);

        // Tanggal libur Budi: kebalikannya.
        $this->assertNull($this->baris($this->ani, $this->liburBudi)->shift_id);
        $this->assertSame($this->pagi->id, $this->baris($this->budi, $this->liburBudi)->shift_id);
    }

    /**
     * Status harus ikut ditukar. Kalau tidak, baris yang menerima shift tetap
     * Off dan absensinya terbaca Libur padahal orangnya masuk — tanpa error.
     */
    public function test_status_ikut_berubah_bukan_cuma_shiftnya(): void
    {
        $this->ajukanDanSetujui();

        $this->assertSame(AssignmentStatus::Scheduled, $this->baris($this->ani, $this->liburAni)->status);
        $this->assertSame(AssignmentStatus::Off, $this->baris($this->budi, $this->liburAni)->status);

        $this->assertSame(AssignmentStatus::Off, $this->baris($this->ani, $this->liburBudi)->status);
        $this->assertSame(AssignmentStatus::Scheduled, $this->baris($this->budi, $this->liburBudi)->status);
    }

    /** Kepemilikan baris tidak pernah disentuh — itu inti mesin penukarnya. */
    public function test_kepemilikan_baris_tidak_berpindah(): void
    {
        $barisAni = $this->baris($this->ani, $this->liburAni)->id;
        $barisBudi = $this->baris($this->budi, $this->liburBudi)->id;

        $this->ajukanDanSetujui();

        $this->assertSame($this->ani->id, RosterAssignment::find($barisAni)->employee_id);
        $this->assertSame($this->budi->id, RosterAssignment::find($barisBudi)->employee_id);
    }

    public function test_tidak_ada_baris_dobel_setelah_ditukar(): void
    {
        $this->ajukanDanSetujui();

        foreach ([$this->ani, $this->budi] as $orang) {
            foreach ([$this->liburAni, $this->liburBudi] as $tanggal) {
                $this->assertSame(1, RosterAssignment::where('employee_id', $orang->id)
                    ->whereDate('work_date', $tanggal)->count());
            }
        }
    }

    /** Belum disetujui rekan & manajer berarti roster belum boleh berubah. */
    public function test_pengajuan_saja_belum_mengubah_roster(): void
    {
        $this->ajukan();

        $this->assertNull($this->baris($this->ani, $this->liburAni)->shift_id);
        $this->assertSame($this->pagi->id, $this->baris($this->budi, $this->liburAni)->shift_id);
    }

    /**
     * Kalau rekan tidak bekerja di tanggal libur pengaju, tukarnya tidak impas:
     * pengaju kehilangan libur tanpa ada yang menggantikan. Harus ditolak
     * dengan alasan yang bisa dipahami, bukan menghasilkan setengah tukar.
     */
    public function test_ditolak_kalau_rekan_tidak_kerja_di_tanggal_libur_pengaju(): void
    {
        // Budi jadi ikut libur di tanggal libur Ani.
        $this->jadwalkan($this->budi, $this->liburAni, null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak dijadwalkan kerja');

        $this->ajukan();
    }

    public function test_ditolak_kalau_pengaju_tidak_kerja_di_tanggal_libur_rekan(): void
    {
        $this->jadwalkan($this->ani, $this->liburBudi, null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak dijadwalkan kerja');

        $this->ajukan();
    }

    /** Hari kerja bukan hari libur — tidak bisa ditukar lewat jalur ini. */
    public function test_ditolak_kalau_yang_dipilih_bukan_hari_libur(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hari libur biasa');

        $this->ajukan(milikSaya: $this->baris($this->ani, $this->liburBudi)->id);
    }

    /** Libur milik sendiri di dua tanggal bukan "tukar dengan rekan". */
    public function test_ditolak_kalau_rekannya_diri_sendiri(): void
    {
        $this->jadwalkan($this->ani, Carbon::parse('2026-08-19', 'Asia/Jakarta'), null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bukan milik Anda sendiri');

        $this->ajukan(milikRekan: $this->baris($this->ani, Carbon::parse('2026-08-19', 'Asia/Jakarta'))->id);
    }

    public function test_ditolak_kalau_tanggalnya_sudah_lewat(): void
    {
        $lewat = Carbon::parse('2026-08-05', 'Asia/Jakarta');
        $this->jadwalkan($this->ani, $lewat, null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sudah lewat');

        $this->ajukan(milikSaya: $this->baris($this->ani, $lewat)->id);
    }

    public function test_ditolak_kalau_libur_orang_lain_diklaim_sebagai_milik_sendiri(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bukan milik Anda');

        $this->ajukan(milikSaya: $this->baris($this->budi, $this->liburBudi)->id);
    }
}
