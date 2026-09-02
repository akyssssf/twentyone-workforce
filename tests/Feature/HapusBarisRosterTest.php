<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Request;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\ShiftSwapRequest;
use App\Services\Roster\RosterService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `roster:hapus` — buang satu baris roster yang keliru.
 *
 * Kasus nyatanya: seseorang punya DUA baris di hari yang sama, Shift Pagi
 * (08:00–18:00) dan Shift Malam (14:00–01:00). Dua shift itu tumpang tindih,
 * mustahil dijalani bersamaan. Akibatnya scan pulang jam 18:15 — yang jelas
 * milik shift pagi — direbut baris Malam, karena 18:15 masih di dalam rentang
 * Malam tapi sudah 15 menit di luar rentang Pagi. Shift Pagi kehilangan jam
 * pulangnya, dan Shift Malam mendapat jam masuk palsu berikut telat 4 jam.
 *
 * `roster:set` sengaja tidak bisa membereskannya: dia melindungi baris
 * ber-source swap/leave supaya keputusan yang sudah disetujui tidak hilang.
 */
class HapusBarisRosterTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $karyawan;

    protected Carbon $tanggal;

    protected Shift $pagi;

    protected Shift $malam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00:00', 'Asia/Jakarta'));

        // Jam kafe sebenarnya; seeder masih membawa jam lama.
        Shift::where('code', 'pagi')->update(['start_time' => '08:00:00', 'end_time' => '18:00:00']);
        Shift::where('code', 'malam')->update(['start_time' => '14:00:00', 'end_time' => '01:00:00']);

        $this->pagi = Shift::where('code', 'pagi')->firstOrFail();
        $this->malam = Shift::where('code', 'malam')->firstOrFail();
        $this->tanggal = Carbon::parse('2026-08-28', 'Asia/Jakarta');

        $this->karyawan = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Uji Hapus',
            'pin_device' => '91',
            'default_shift_id' => $this->pagi->id,
        ]);

        $roster = app(RosterService::class);
        $baris = $roster->assign(
            $roster->findOrCreate(2026, 8),
            $this->karyawan,
            $this->tanggal,
            $this->pagi->id,
        );

        // Baris kedua bersumber swap — persis yang dilindungi roster:set.
        RosterAssignment::create([
            'roster_id' => $baris->roster_id,
            'employee_id' => $this->karyawan->id,
            'work_date' => $this->tanggal->copy()->startOfDay(),
            'shift_id' => $this->malam->id,
            'status' => AssignmentStatus::Scheduled,
            'source' => 'swap',
        ]);

        foreach (['07:59:48', '08:02:28', '18:15:15', '18:19:18'] as $jam) {
            $at = Carbon::parse("2026-08-28 {$jam}", 'Asia/Jakarta');

            AttendanceLog::create([
                'cloud_id' => 'UJI',
                'employee_id' => $this->karyawan->id,
                'pin' => '91',
                'scanned_at' => $at,
                'scan_minute' => $at->copy()->startOfMinute(),
                'source' => 'webhook',
            ]);
        }

        Artisan::call('attendance:compute', [
            '--from' => '2026-08-28', '--to' => '2026-08-28',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function rekap(Shift $shift): ?Attendance
    {
        return Attendance::where('employee_id', $this->karyawan->id)
            ->whereDate('work_date', $this->tanggal)
            ->where('shift_key', $shift->id)
            ->first();
    }

    /** Kondisi awal: inilah kerusakan yang mau dibereskan. */
    public function test_baris_dobel_merebut_jam_pulang_shift_yang_benar(): void
    {
        $this->assertSame('07:59:48', $this->rekap($this->pagi)?->check_in_at?->format('H:i:s'));
        $this->assertNull($this->rekap($this->pagi)?->check_out_at);

        $this->assertSame('18:15:15', $this->rekap($this->malam)?->check_in_at?->format('H:i:s'));
        $this->assertGreaterThan(240, (int) $this->rekap($this->malam)?->late_minutes);
    }

    public function test_menghapus_baris_keliru_mengembalikan_jam_pulang(): void
    {
        $this->artisan('roster:hapus 91 2026-08-28 malam')->assertSuccessful();

        $this->assertNull($this->rekap($this->malam));

        // Scan 18:15 kembali jadi jam pulang shift pagi, dan yang terakhir
        // (18:19) yang dipakai karena masih di jendela tangkap jam pulang.
        $this->assertSame('07:59:48', $this->rekap($this->pagi)?->check_in_at?->format('H:i:s'));
        $this->assertSame('18:19:18', $this->rekap($this->pagi)?->check_out_at?->format('H:i:s'));
        $this->assertSame(0, (int) $this->rekap($this->pagi)?->late_minutes);
    }

    /**
     * Penjagaan terpenting: FK-nya cascadeOnDelete, jadi menghapus baris yang
     * jadi jadwal pengaju sebuah tukar shift akan IKUT MENGHAPUS riwayat
     * pengajuannya — tanpa error, tanpa jejak.
     */
    public function test_menolak_menghapus_baris_yang_dirujuk_pengajuan_tukar(): void
    {
        $barisMalam = RosterAssignment::where('employee_id', $this->karyawan->id)
            ->where('shift_id', $this->malam->id)->sole();

        $pengajuan = Request::create([
            'code' => 'UJI-001',
            'branch_id' => Branch::current()->id,
            'type' => RequestType::Swap,
            'employee_id' => $this->karyawan->id,
            'status' => RequestStatus::PendingPeer,
            'submitted_at' => now(),
        ]);

        ShiftSwapRequest::create([
            'request_id' => $pengajuan->id,
            'requester_assignment_id' => $barisMalam->id,
            'partner_employee_id' => $this->karyawan->id,
            'reason' => 'Uji rujukan',
        ]);

        $this->artisan('roster:hapus 91 2026-08-28 malam')->assertFailed();

        $this->assertNotNull(RosterAssignment::find($barisMalam->id));
        $this->assertNotNull(Request::find($pengajuan->id));
    }

    /**
     * Rentang: buat orang yang sudah keluar tapi rosternya terlanjur terisi
     * sebulan penuh. Menghapusnya satu per satu berarti tiga puluh perintah,
     * dan yang mengetik tiga puluh perintah akan melewatkan satu.
     */
    public function test_menghapus_serentang_sekaligus(): void
    {
        $roster = app(RosterService::class);

        foreach (range(1, 5) as $hari) {
            $roster->assign(
                $roster->findOrCreate(2026, 9),
                $this->karyawan,
                Carbon::create(2026, 9, $hari, 0, 0, 0, 'Asia/Jakarta'),
                $this->pagi->id,
            );
        }

        $this->artisan('roster:hapus 91 2026-09-01..2026-09-05')
            ->expectsConfirmation('Hapus 5 baris ini?', 'yes')
            ->assertSuccessful();

        $this->assertSame(0, RosterAssignment::where('employee_id', $this->karyawan->id)
            ->whereBetween('work_date', ['2026-09-01 00:00:00', '2026-09-05 23:59:59'])
            ->count());
    }

    /** Menjawab tidak berarti tidak ada yang tersentuh sama sekali. */
    public function test_bisa_dibatalkan_saat_konfirmasi(): void
    {
        $roster = app(RosterService::class);

        foreach (range(1, 3) as $hari) {
            $roster->assign(
                $roster->findOrCreate(2026, 9),
                $this->karyawan,
                Carbon::create(2026, 9, $hari, 0, 0, 0, 'Asia/Jakarta'),
                $this->pagi->id,
            );
        }

        $this->artisan('roster:hapus 91 2026-09-01..2026-09-03')
            ->expectsConfirmation('Hapus 3 baris ini?', 'no')
            ->assertSuccessful();

        $this->assertSame(3, RosterAssignment::where('employee_id', $this->karyawan->id)
            ->whereBetween('work_date', ['2026-09-01 00:00:00', '2026-09-03 23:59:59'])
            ->count());
    }

    public function test_rentang_terbalik_ditolak(): void
    {
        $this->artisan('roster:hapus 91 2026-09-05..2026-09-01')->assertFailed();
    }

    public function test_baris_yang_tidak_ada_ditolak(): void
    {
        $this->artisan('roster:hapus 91 2026-08-28 middle')->assertFailed();
    }

    public function test_pin_tidak_dikenal_ditolak(): void
    {
        $this->artisan('roster:hapus 999 2026-08-28 malam')->assertFailed();
    }
}
