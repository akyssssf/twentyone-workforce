<?php

namespace Tests\Feature;

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

/** `attendance:scan` — scan mentah lintas hari, untuk mencari jadwal yang tergeser. */
class DaftarScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_di_hari_libur_ditandai(): void
    {
        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'Asia/Jakarta'));

        $pagi = Shift::where('code', 'pagi')->firstOrFail();
        $e = Employee::factory()->create(['branch_id' => Branch::current()->id, 'name' => 'Uji Scan', 'pin_device' => '88']);

        $service = app(RosterService::class);
        $roster = $service->findOrCreate(2026, 10);
        $service->assign($roster, $e, Carbon::parse('2026-10-14'), null);       // libur
        $service->assign($roster, $e, Carbon::parse('2026-10-15'), $pagi->id);  // kerja, tanpa scan

        $at = Carbon::parse('2026-10-14 08:05:00', 'Asia/Jakarta');
        AttendanceLog::create([
            'cloud_id' => 'UJI', 'employee_id' => $e->id, 'pin' => '88',
            'scanned_at' => $at, 'scan_minute' => $at->copy()->startOfMinute(), 'source' => 'webhook',
        ]);

        $this->assertSame(0, Artisan::call('attendance:scan', ['pin' => '88', '--from' => '2026-10-13', '--to' => '2026-10-15']));

        $keluaran = Artisan::output();
        $this->assertStringContainsString('08:05:00', $keluaran);
        $this->assertStringContainsString('ada scan di hari libur', $keluaran);
        $this->assertStringContainsString('Ada scan di hari yang jadwalnya libur/kosong: 2026-10-14', $keluaran);

        Carbon::setTestNow();
    }

    /** Tanpa PIN: semua karyawan pada satu tanggal, dengan jadwal dan scan masing-masing. */
    public function test_tanpa_pin_menampilkan_semua_karyawan(): void
    {
        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00', 'Asia/Jakarta'));

        $malam = Shift::where('code', 'malam')->firstOrFail();
        $a = Employee::factory()->create(['branch_id' => Branch::current()->id, 'name' => 'Ada Scan', 'pin_device' => '81']);
        Employee::factory()->create(['branch_id' => Branch::current()->id, 'name' => 'Tanpa Scan', 'pin_device' => '82']);

        $service = app(RosterService::class);
        $service->assign($service->findOrCreate(2026, 10), $a, Carbon::parse('2026-10-27'), $malam->id);

        $at = Carbon::parse('2026-10-27 07:50:00', 'Asia/Jakarta');
        AttendanceLog::create([
            'cloud_id' => 'UJI', 'employee_id' => $a->id, 'pin' => '81',
            'scanned_at' => $at, 'scan_minute' => $at->copy()->startOfMinute(), 'source' => 'webhook',
        ]);

        $this->assertSame(0, Artisan::call('attendance:scan', ['--from' => '2026-10-27']));
        $keluaran = Artisan::output();

        $this->assertStringContainsString('Ada Scan', $keluaran);
        $this->assertStringContainsString('07:50', $keluaran);
        $this->assertStringContainsString('Tanpa Scan', $keluaran);
        $this->assertStringContainsString('(belum ada jadwal)', $keluaran);

        Carbon::setTestNow();
    }
}
