<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\Roster\RosterService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `shift:edit` — ubah jam master sebuah shift.
 *
 * Jam master berlaku untuk SEMUA tanggal. Itu bukan efek samping yang bisa
 * dihindari, itu sifatnya — dan justru karena itu perubahan jamnya wajib
 * dikonfirmasi, sebelum-sesudahnya ditampilkan, dan efeknya ke telat dikunci
 * tes supaya tidak ada yang mengira perubahan ini cuma berlaku ke depan.
 */
class EditShiftTest extends TestCase
{
    use RefreshDatabase;

    protected Shift $middle;

    protected Employee $karyawan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'Asia/Jakarta'));

        $this->middle = Shift::where('code', 'middle')->firstOrFail();

        $this->karyawan = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Uji Middle',
            'pin_device' => '51',
            'default_shift_id' => $this->middle->id,
        ]);

        $service = app(RosterService::class);
        $service->assign(
            $service->findOrCreate(2026, 9),
            $this->karyawan,
            Carbon::parse('2026-09-12', 'Asia/Jakarta'),
            $this->middle->id,
        );

        // Datang 11:50 — telat 20 menit kalau shift mulai 11:30, tepat waktu
        // kalau mulai 12:00.
        $at = Carbon::parse('2026-09-12 11:50:00', 'Asia/Jakarta');

        AttendanceLog::create([
            'cloud_id' => 'UJI',
            'employee_id' => $this->karyawan->id,
            'pin' => '51',
            'scanned_at' => $at,
            'scan_minute' => $at->copy()->startOfMinute(),
            'source' => 'webhook',
        ]);

        Artisan::call('attendance:compute', ['--from' => '2026-09-12', '--to' => '2026-09-12']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function rekap(): ?Attendance
    {
        return Attendance::where('employee_id', $this->karyawan->id)
            ->whereDate('work_date', Carbon::parse('2026-09-12'))
            ->first();
    }

    public function test_mengubah_jam_master_lalu_dihitung_ulang_mengubah_telat(): void
    {
        $this->assertSame(20, (int) $this->rekap()?->late_minutes);

        $this->artisan('shift:edit middle --mulai=12:00 --ya')->assertSuccessful();

        $this->assertSame('12:00:00', $this->middle->fresh()->start_time);

        // Jam master berlaku ke tanggal LAMA begitu dihitung ulang.
        Artisan::call('attendance:compute', ['--from' => '2026-09-12', '--to' => '2026-09-12']);
        $this->assertSame(0, (int) $this->rekap()?->late_minutes);
    }

    /** Perubahan jam wajib dikonfirmasi; menolak berarti tidak ada yang tersentuh. */
    public function test_menolak_konfirmasi_tidak_mengubah_apa_pun(): void
    {
        $this->artisan('shift:edit middle --mulai=12:00')
            ->expectsConfirmation('Lanjutkan?', 'no')
            ->assertSuccessful();

        $this->assertSame('11:30:00', $this->middle->fresh()->start_time);
    }

    /** Jam pulang <= jam masuk berarti lewat tengah malam; kolomnya ikut. */
    public function test_crosses_midnight_ikut_disesuaikan(): void
    {
        $this->artisan('shift:edit middle --mulai=12:00 --selesai=20:00 --ya')->assertSuccessful();
        $this->assertFalse($this->middle->fresh()->crosses_midnight);

        $this->artisan('shift:edit middle --selesai=01:00 --ya')->assertSuccessful();
        $this->assertTrue($this->middle->fresh()->crosses_midnight);
    }

    /** Menampilkan jam tidak menyentuh jamnya, jadi tidak perlu konfirmasi. */
    public function test_tampilkan_jam_tanpa_mengubah_jam(): void
    {
        $this->assertFalse($this->middle->show_hours);

        $this->artisan('shift:edit middle --tampilkan-jam')->assertSuccessful();

        $this->assertTrue($this->middle->fresh()->show_hours);
        $this->assertSame('11:30:00', $this->middle->fresh()->start_time);
    }

    public function test_jam_ngawur_ditolak(): void
    {
        $this->artisan('shift:edit middle --mulai=25:00 --ya')->assertFailed();
        $this->artisan('shift:edit middle --mulai=duabelas --ya')->assertFailed();

        $this->assertSame('11:30:00', $this->middle->fresh()->start_time);
    }

    public function test_tanpa_perubahan_ditolak(): void
    {
        $this->artisan('shift:edit middle')->assertFailed();
    }

    public function test_kode_tidak_dikenal_ditolak(): void
    {
        $this->artisan('shift:edit sore --mulai=12:00 --ya')->assertFailed();
    }
}
