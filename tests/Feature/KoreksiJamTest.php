<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\AttendanceAdjustment;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Services\Roster\RosterService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `attendance:jam` — koreksi jam masuk/pulang untuk yang lupa menempel jari.
 *
 * Sebelum ini satu-satunya jalur adalah karyawannya mengajukan koreksi sendiri
 * lewat aplikasi — dan orang yang sudah lupa absen biasanya juga tidak
 * mengajukan. Yang dijaga paling keras: koreksinya harus bertahan melewati cron
 * yang menghitung ulang tiap 15 menit.
 */
class KoreksiJamTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $karyawan;

    protected Shift $pagi;

    protected Shift $malam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-05 12:00:00', 'Asia/Jakarta'));

        Shift::where('code', 'pagi')->update(['start_time' => '08:00:00', 'end_time' => '18:00:00']);
        Shift::where('code', 'malam')->update(['start_time' => '14:00:00', 'end_time' => '01:00:00']);

        $this->pagi = Shift::where('code', 'pagi')->firstOrFail();
        $this->malam = Shift::where('code', 'malam')->firstOrFail();

        $this->karyawan = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Lupa Absen',
            'pin_device' => '31',
            'default_shift_id' => $this->pagi->id,
        ]);

        $this->jadwalkan($this->pagi);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function jadwalkan(Shift $shift): RosterAssignment
    {
        $service = app(RosterService::class);

        return $service->assign(
            $service->findOrCreate(2026, 9),
            $this->karyawan,
            Carbon::parse('2026-09-01', 'Asia/Jakarta'),
            $shift->id,
        );
    }

    protected function rekap(): ?Attendance
    {
        return Attendance::where('employee_id', $this->karyawan->id)
            ->whereDate('work_date', Carbon::parse('2026-09-01'))
            ->first();
    }

    /** Tidak ada scan sama sekali = Alpha. Inilah titik awalnya. */
    public function test_lupa_absen_bisa_dikoreksi_jam_masuk_dan_pulangnya(): void
    {
        Artisan::call('attendance:compute', ['--from' => '2026-09-01', '--to' => '2026-09-01']);
        $this->assertSame(AttendanceStatus::Alpha, $this->rekap()?->status);

        $this->artisan('attendance:jam 31 2026-09-01 --masuk=08:00 --pulang=18:00 --alasan="Lupa tempel jari, ada saksi"')
            ->assertSuccessful();

        $this->assertSame('08:00', $this->rekap()?->check_in_at?->format('H:i'));
        $this->assertSame('18:00', $this->rekap()?->check_out_at?->format('H:i'));
        $this->assertSame(AttendanceStatus::Hadir, $this->rekap()?->status);
        $this->assertSame(600, (int) $this->rekap()?->work_minutes);
    }

    /** Yang paling penting: cron tidak boleh menghapusnya. */
    public function test_koreksi_bertahan_setelah_dihitung_ulang(): void
    {
        $this->artisan('attendance:jam 31 2026-09-01 --masuk=08:00 --alasan="Lupa tempel jari"');

        Artisan::call('attendance:compute', ['--from' => '2026-09-01', '--to' => '2026-09-01']);

        $this->assertSame('08:00', $this->rekap()?->check_in_at?->format('H:i'));
    }

    /**
     * Jam pulang shift malam jatuh di tanggal BERIKUTNYA. Kalau diambil apa
     * adanya, 01:00 jadi dini hari tanggal yang sama — jam kerjanya berubah
     * jadi negatif belasan jam tanpa ada yang menandai.
     */
    public function test_jam_pulang_lewat_tengah_malam_masuk_tanggal_berikutnya(): void
    {
        RosterAssignment::where('employee_id', $this->karyawan->id)->delete();
        $this->jadwalkan($this->malam);

        $this->artisan('attendance:jam 31 2026-09-01 --masuk=14:00 --pulang=01:00 --alasan="Lupa tempel jari"')
            ->assertSuccessful();

        $this->assertSame('2026-09-02 01:00', $this->rekap()?->check_out_at?->format('Y-m-d H:i'));
        $this->assertSame(660, (int) $this->rekap()?->work_minutes);
    }

    public function test_bisa_dibatalkan(): void
    {
        $this->artisan('attendance:jam 31 2026-09-01 --masuk=08:00 --alasan="Lupa tempel jari"');
        $this->assertNotNull($this->rekap()?->check_in_at);

        $this->artisan('attendance:jam 31 2026-09-01 --batal')->assertSuccessful();

        $this->assertNull($this->rekap()?->check_in_at);
    }

    /** Koreksi ulang tidak boleh menumpuk jadi dua yang aktif bersamaan. */
    public function test_koreksi_ulang_membatalkan_yang_lama(): void
    {
        $this->artisan('attendance:jam 31 2026-09-01 --masuk=08:00 --alasan="Kata dia jam 8"');
        $this->artisan('attendance:jam 31 2026-09-01 --masuk=07:45 --alasan="Ternyata jam 7:45"');

        $aktif = AttendanceAdjustment::where('type', 'set_check_in')->whereNull('reverted_by_id')->get();

        $this->assertCount(1, $aktif);
        $this->assertSame('07:45', $this->rekap()?->check_in_at?->format('H:i'));
    }

    /**
     * Dua jadwal di hari yang sama: shiftnya wajib disebut. Menebaknya berarti
     * jam kerja bisa menempel di shift yang salah tanpa ada yang sadar.
     */
    public function test_dua_jadwal_wajib_menyebut_shift(): void
    {
        $baris = RosterAssignment::where('employee_id', $this->karyawan->id)->sole();

        RosterAssignment::create([
            'roster_id' => $baris->roster_id,
            'employee_id' => $this->karyawan->id,
            'work_date' => Carbon::parse('2026-09-01', 'Asia/Jakarta')->startOfDay(),
            'shift_id' => $this->malam->id,
            'status' => AssignmentStatus::Scheduled,
            'source' => 'manual',
        ]);

        $this->artisan('attendance:jam 31 2026-09-01 --masuk=08:00 --alasan="Lupa"')->assertFailed();

        $this->artisan('attendance:jam 31 2026-09-01 --masuk=08:00 --shift=pagi --alasan="Lupa"')
            ->assertSuccessful();
    }

    public function test_tanpa_alasan_ditolak(): void
    {
        $this->artisan('attendance:jam 31 2026-09-01 --masuk=08:00')->assertFailed();
    }

    public function test_tanpa_masuk_maupun_pulang_ditolak(): void
    {
        $this->artisan('attendance:jam 31 2026-09-01 --alasan="Lupa"')->assertFailed();
    }

    public function test_jam_ngawur_ditolak(): void
    {
        $this->artisan('attendance:jam 31 2026-09-01 --masuk=25:00 --alasan="Lupa"')->assertFailed();
        $this->artisan('attendance:jam 31 2026-09-01 --masuk=delapan --alasan="Lupa"')->assertFailed();
    }

    public function test_pin_tidak_dikenal_ditolak(): void
    {
        $this->artisan('attendance:jam 999 2026-09-01 --masuk=08:00 --alasan="Lupa"')->assertFailed();
    }
}
