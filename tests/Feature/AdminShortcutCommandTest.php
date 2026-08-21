<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceAdjustment;
use App\Models\AttendanceLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Services\Roster\RosterService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dua perintah pintasan untuk pekerjaan admin sehari-hari: memaafkan telat dan
 * mengubah jadwal satu orang. Sebelum ada ini, dua hal itu cuma bisa lewat
 * skrip PHP panjang yang ditempel ke SSH — dan yang gampang kelewat separuh.
 */
class AdminShortcutCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $karyawan;

    protected Shift $pagi;

    protected Shift $malam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'Asia/Jakarta'));

        $this->pagi = Shift::where('code', 'pagi')->firstOrFail();
        $this->malam = Shift::where('code', 'malam')->firstOrFail();

        $this->karyawan = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Uji Coba',
            'pin_device' => '77',
            'default_shift_id' => $this->pagi->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function jadwalkanDanScan(string $tanggal, string $jamScan): void
    {
        $service = app(RosterService::class);
        $service->assign(
            $service->findOrCreate(2026, 8),
            $this->karyawan,
            Carbon::parse($tanggal, 'Asia/Jakarta'),
            $this->pagi->id,
        );

        $at = Carbon::parse("{$tanggal} {$jamScan}", 'Asia/Jakarta');

        AttendanceLog::create([
            'cloud_id' => 'UJI',
            'employee_id' => $this->karyawan->id,
            'pin' => $this->karyawan->pin_device,
            'scanned_at' => $at,
            'scan_minute' => $at->copy()->startOfMinute(),
            'source' => 'webhook',
        ]);
    }

    protected function telatMenit(string $tanggal): int
    {
        return (int) Attendance::where('employee_id', $this->karyawan->id)
            ->whereDate('work_date', Carbon::parse($tanggal))
            ->value('late_minutes');
    }

    public function test_maafkan_telat_menolkan_lalu_bisa_dibatalkan(): void
    {
        // Shift pagi seeder mulai 09:00; datang 10:30 berarti telat 90 menit.
        $this->jadwalkanDanScan('2026-08-19', '10:30:00');
        $this->artisan('attendance:compute --from=2026-08-19 --to=2026-08-19');

        $this->assertSame(90, $this->telatMenit('2026-08-19'));

        $this->artisan('attendance:waive-late 77 2026-08-19 --alasan="Motor mogok"')
            ->assertSuccessful();

        $this->assertSame(0, $this->telatMenit('2026-08-19'));

        // Dibatalkan: telatnya harus muncul lagi apa adanya.
        $this->artisan('attendance:waive-late 77 2026-08-19 --batal')->assertSuccessful();

        $this->assertSame(90, $this->telatMenit('2026-08-19'));
    }

    /**
     * Koreksi hidup sebagai INPUT di attendance_adjustments, bukan tulisan
     * langsung ke attendances — jadi cron attendance:compute yang jalan tiap
     * 15 menit tidak menghapusnya.
     */
    public function test_maaf_bertahan_setelah_dihitung_ulang(): void
    {
        $this->jadwalkanDanScan('2026-08-19', '10:30:00');
        $this->artisan('attendance:compute --from=2026-08-19 --to=2026-08-19');
        $this->artisan('attendance:waive-late 77 2026-08-19 --alasan="Motor mogok"');

        $this->artisan('attendance:compute --from=2026-08-19 --to=2026-08-19');
        $this->artisan('attendance:compute --from=2026-08-19 --to=2026-08-19');

        $this->assertSame(0, $this->telatMenit('2026-08-19'));
    }

    public function test_maafkan_telat_tanpa_alasan_ditolak(): void
    {
        $this->artisan('attendance:waive-late 77 2026-08-19')->assertFailed();

        $this->assertSame(0, AttendanceAdjustment::where('type', 'waive_late')->count());
    }

    public function test_roster_set_mengubah_beberapa_tanggal_sekaligus(): void
    {
        $this->artisan('roster:set 77 2026-08-21=malam 2026-08-22=pagi 2026-08-23=libur')
            ->assertSuccessful();

        $baris = RosterAssignment::with('shift')
            ->where('employee_id', $this->karyawan->id)
            ->whereBetween('work_date', ['2026-08-21 00:00:00', '2026-08-23 23:59:59'])
            ->orderBy('work_date')->get();

        $this->assertCount(3, $baris);
        $this->assertSame($this->malam->id, $baris[0]->shift_id);
        $this->assertSame($this->pagi->id, $baris[1]->shift_id);
        $this->assertNull($baris[2]->shift_id);
    }

    /**
     * Rentang tanggal, untuk posisi yang masuk hampir tiap hari (Logistik).
     * Menulis 30 pasangan satu per satu adalah undangan untuk kelewat satu.
     */
    public function test_roster_set_menerima_rentang_tanggal(): void
    {
        $this->artisan('roster:set 77 2026-09-01..2026-09-30=pagi')->assertSuccessful();

        $baris = RosterAssignment::where('employee_id', $this->karyawan->id)
            ->whereBetween('work_date', ['2026-09-01 00:00:00', '2026-09-30 23:59:59'])
            ->get();

        $this->assertCount(30, $baris);
        $this->assertTrue($baris->every(fn ($b) => $b->shift_id === $this->pagi->id));
    }

    /** Rentang dan tanggal tunggal boleh dicampur dalam satu perintah. */
    public function test_rentang_bisa_dicampur_dengan_tanggal_tunggal(): void
    {
        $this->artisan('roster:set 77 2026-09-01..2026-09-05=pagi 2026-09-03=libur')
            ->assertSuccessful();

        $tanggal = fn (string $t) => RosterAssignment::where('employee_id', $this->karyawan->id)
            ->whereDate('work_date', Carbon::parse($t))->first();

        // Yang belakangan menang: 3 September jadi libur walaupun rentangnya
        // sudah menjadwalkannya pagi.
        $this->assertSame($this->pagi->id, $tanggal('2026-09-02')->shift_id);
        $this->assertNull($tanggal('2026-09-03')->shift_id);
        $this->assertSame($this->pagi->id, $tanggal('2026-09-04')->shift_id);
    }

    /** Salah ketik tahun tidak boleh diam-diam membuat ribuan baris. */
    public function test_rentang_kepanjangan_ditolak(): void
    {
        $this->artisan('roster:set 77 2026-09-01..2027-09-30=pagi')->assertFailed();

        $this->assertSame(0, RosterAssignment::where('employee_id', $this->karyawan->id)->count());
    }

    public function test_rentang_terbalik_ditolak(): void
    {
        $this->artisan('roster:set 77 2026-09-30..2026-09-01=pagi')->assertFailed();

        $this->assertSame(0, RosterAssignment::where('employee_id', $this->karyawan->id)->count());
    }

    /** Mengubah shift harus MEMINDAHKAN, bukan menambah baris kedua. */
    public function test_roster_set_dua_kali_tetap_satu_baris(): void
    {
        $this->artisan('roster:set 77 2026-08-21=pagi');
        $this->artisan('roster:set 77 2026-08-21=malam');

        $baris = RosterAssignment::where('employee_id', $this->karyawan->id)
            ->whereDate('work_date', Carbon::parse('2026-08-21'))->get();

        $this->assertCount(1, $baris);
        $this->assertSame($this->malam->id, $baris->first()->shift_id);
    }

    public function test_roster_set_menolak_menimpa_cuti_yang_disetujui(): void
    {
        app(RosterService::class)->markLeave(
            $this->karyawan,
            Carbon::parse('2026-08-21', 'Asia/Jakarta'),
            requestId: 3,
        );

        $this->artisan('roster:set 77 2026-08-21=malam')->assertFailed();

        $baris = RosterAssignment::where('employee_id', $this->karyawan->id)
            ->whereDate('work_date', Carbon::parse('2026-08-21'))->get();

        $this->assertCount(1, $baris);
        $this->assertSame('leave', $baris->first()->status->value);
    }

    public function test_roster_set_menolak_kode_shift_yang_tidak_ada(): void
    {
        $this->artisan('roster:set 77 2026-08-21=ngawur')->assertFailed();
    }
}
