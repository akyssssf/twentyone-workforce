<?php

namespace Tests\Feature;

use App\Models\Attendance;
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
 * Jam shift khusus untuk satu tanggal.
 *
 * Jam di master shift berlaku global, jadi mengubahnya untuk sehari ikut
 * mengubah tanggal lain — termasuk yang sudah lewat, karena cron menghitung
 * ulang dua hari terakhir tiap 15 menit. Karena itu jam khusus ditaruh di
 * roster_assignments, bukan dengan mengubah master atau membuat shift baru.
 */
class JamKhususTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $karyawan;

    protected Shift $pagi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);

        // Dipatok jauh setelah tanggal uji supaya jendela kerjanya sudah tutup.
        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00', 'Asia/Jakarta'));

        $this->pagi = Shift::where('code', 'pagi')->firstOrFail();

        $this->karyawan = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Uji Jam Khusus',
            'pin_device' => '88',
            'default_shift_id' => $this->pagi->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function jadwalkan(string $tanggal): RosterAssignment
    {
        $service = app(RosterService::class);

        return $service->assign(
            $service->findOrCreate(2026, 8),
            $this->karyawan,
            Carbon::parse($tanggal, 'Asia/Jakarta'),
            $this->pagi->id,
        );
    }

    protected function scan(string $waktu): void
    {
        $at = Carbon::parse($waktu, 'Asia/Jakarta');

        AttendanceLog::create([
            'cloud_id' => 'UJI',
            'employee_id' => $this->karyawan->id,
            'pin' => $this->karyawan->pin_device,
            'scanned_at' => $at,
            'scan_minute' => $at->copy()->startOfMinute(),
            'source' => 'webhook',
        ]);
    }

    protected function rekap(string $tanggal): ?Attendance
    {
        return Attendance::where('employee_id', $this->karyawan->id)
            ->whereDate('work_date', Carbon::parse($tanggal))
            ->first();
    }

    /** Shift pagi seeder mulai 09:00. Dengan jam khusus 08:00, datang 08:30 jadi telat. */
    public function test_telat_dihitung_dari_jam_khusus_bukan_jam_master(): void
    {
        $this->jadwalkan('2026-08-21');
        $this->scan('2026-08-21 08:30:00');

        // Tanpa jam khusus: 08:30 masih SEBELUM 09:00, jadi tidak telat.
        $this->artisan('attendance:compute --from=2026-08-21 --to=2026-08-21');
        $this->assertSame(0, $this->rekap('2026-08-21')->late_minutes);

        $this->artisan('roster:jam-khusus 2026-08-21 pagi 08:00 16:00 --recompute')
            ->assertSuccessful();

        // Dengan jam khusus mulai 08:00: 08:30 berarti telat 30 menit.
        $this->assertSame(30, $this->rekap('2026-08-21')->late_minutes);
        $this->assertSame('08:00', $this->rekap('2026-08-21')->scheduled_in->format('H:i'));
        $this->assertSame('16:00', $this->rekap('2026-08-21')->scheduled_out->format('H:i'));
    }

    /** Yang paling penting: tanggal LAIN tidak boleh ikut berubah. */
    public function test_tanggal_lain_tidak_ikut_berubah(): void
    {
        $this->jadwalkan('2026-08-21');
        $this->jadwalkan('2026-08-22');
        $this->scan('2026-08-22 08:30:00');

        $this->artisan('roster:jam-khusus 2026-08-21 pagi 08:00 16:00');
        $this->artisan('attendance:compute --from=2026-08-22 --to=2026-08-22');

        // 22 Agustus tetap pakai jam master (09:00), jadi 08:30 tidak telat.
        $this->assertSame(0, $this->rekap('2026-08-22')->late_minutes);
        $this->assertSame('09:00', $this->rekap('2026-08-22')->scheduled_in->format('H:i'));

        // Master shift-nya sendiri tidak boleh tersentuh.
        $this->assertSame('09:00:00', $this->pagi->fresh()->start_time);
    }

    public function test_pulang_cepat_dihitung_dari_jam_khusus(): void
    {
        $this->jadwalkan('2026-08-21');
        $this->scan('2026-08-21 07:55:00');
        $this->scan('2026-08-21 15:30:00');

        $this->artisan('roster:jam-khusus 2026-08-21 pagi 08:00 16:00 --recompute');

        // Pulang 15:30 dari jadwal 16:00 = pulang cepat 30 menit.
        $this->assertSame(30, $this->rekap('2026-08-21')->early_leave_minutes);
    }

    public function test_hapus_mengembalikan_ke_jam_master(): void
    {
        $this->jadwalkan('2026-08-21');
        $this->scan('2026-08-21 08:30:00');

        $this->artisan('roster:jam-khusus 2026-08-21 pagi 08:00 16:00 --recompute');
        $this->assertSame(30, $this->rekap('2026-08-21')->late_minutes);

        $this->artisan('roster:jam-khusus 2026-08-21 pagi 08:00 16:00 --hapus --recompute')
            ->assertSuccessful();

        $this->assertSame(0, $this->rekap('2026-08-21')->late_minutes);
        $this->assertSame('09:00', $this->rekap('2026-08-21')->scheduled_in->format('H:i'));
    }

    public function test_jam_ngawur_ditolak(): void
    {
        $this->jadwalkan('2026-08-21');

        $this->artisan('roster:jam-khusus 2026-08-21 pagi 25:00 16:00')->assertFailed();
        $this->artisan('roster:jam-khusus 2026-08-21 pagi delapan 16:00')->assertFailed();
    }

    public function test_shift_yang_tidak_ada_ditolak(): void
    {
        $this->artisan('roster:jam-khusus 2026-08-21 ngawur 08:00 16:00')->assertFailed();
    }
}
