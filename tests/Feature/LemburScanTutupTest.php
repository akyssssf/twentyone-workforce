<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Services\Roster\RosterService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Kasus Giat 25 Okt / 1 Nov 2026: shift pagi, bertahan sampai kafe tutup 23:30.
 *
 * Scan pulang saat tutup jatuh sesudah 23:30:00 (23:32:11, 23:30:36). Dulu
 * scan itu di luar jendela pencarian, sehingga scan SORE (19:30) dianggap akhir
 * lembur: 1j30m dibayar padahal bertahan 5j30m.
 */
class LemburScanTutupTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $giat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-27 12:00:00', 'Asia/Jakarta'));

        Shift::where('code', 'pagi')->update(['start_time' => '08:00:00', 'end_time' => '18:00:00']);
        Shift::where('code', 'malam')->update(['start_time' => '14:00:00', 'end_time' => '23:30:00', 'crosses_midnight' => false]);
        Shift::where('code', 'middle')->update(['end_time' => '23:30:00', 'crosses_midnight' => false]);

        User::factory()->create(['role' => UserRole::Admin]);

        $pagi = Shift::where('code', 'pagi')->firstOrFail();

        $this->giat = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Giat Uji',
            'pin_device' => '4',
            'default_shift_id' => $pagi->id,
        ]);

        $service = app(RosterService::class);
        $service->assign($service->findOrCreate(2026, 10), $this->giat, Carbon::parse('2026-10-25', 'Asia/Jakarta'), $pagi->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function scan(string ...$waktu): void
    {
        foreach ($waktu as $w) {
            $at = Carbon::parse($w, 'Asia/Jakarta');

            AttendanceLog::create([
                'cloud_id' => 'UJI',
                'employee_id' => $this->giat->id,
                'pin' => '4',
                'scanned_at' => $at,
                'scan_minute' => $at->copy()->startOfMinute(),
                'source' => 'webhook',
            ]);
        }

        Artisan::call('attendance:compute', ['--from' => '2026-10-25', '--to' => '2026-10-25']);
    }

    protected function lemburMenit(): int
    {
        return (int) Attendance::where('employee_id', $this->giat->id)
            ->whereDate('work_date', '2026-10-25')
            ->value('overtime_minutes');
    }

    public function test_scan_tutup_sesudah_jam_tutup_tetap_dihitung_sebagai_akhir_lembur(): void
    {
        $this->scan('2026-10-25 07:58:28', '2026-10-25 19:30:15', '2026-10-25 23:32:11');

        $this->artisan('lembur:tugaskan 4 2026-10-25 --keperluan=acara --alasan="Bertahan sampai tutup" --aktifkan')
            ->assertSuccessful();

        // 18:00 sampai tutup 23:30 = 5j30m. Scan 23:32 dibatasi ke jam tutup.
        $this->assertSame(330, $this->lemburMenit());
    }

    public function test_lembur_tidak_melewati_jam_tutup_walau_scan_lebih_lama(): void
    {
        $this->scan('2026-10-25 07:58:28', '2026-10-25 23:50:00');

        $this->artisan('lembur:tugaskan 4 2026-10-25 --keperluan=acara --alasan="Bertahan sampai tutup" --aktifkan')
            ->assertSuccessful();

        $this->assertSame(330, $this->lemburMenit());
    }

    public function test_scan_sore_tanpa_scan_tutup_tetap_berhenti_di_scan_sore(): void
    {
        $this->scan('2026-10-25 07:58:28', '2026-10-25 19:30:15');

        $this->artisan('lembur:tugaskan 4 2026-10-25 --keperluan=acara --alasan="Sampai sore" --aktifkan')
            ->assertSuccessful();

        $this->assertSame(90, $this->lemburMenit());
    }
}
