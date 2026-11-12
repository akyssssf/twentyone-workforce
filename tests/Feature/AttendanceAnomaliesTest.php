<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\Attendance\AttendanceComputer;
use App\Services\Roster\RosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AttendanceAnomaliesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-12 12:00:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function scan(string $pin, string $waktu): void
    {
        $at = Carbon::parse($waktu, 'Asia/Jakarta');

        AttendanceLog::create([
            'cloud_id' => 'X', 'pin' => $pin, 'scanned_at' => $at,
            'scan_minute' => $at->copy()->startOfMinute(), 'source' => 'webhook',
        ]);
    }

    public function test_menandai_telat_besar_dan_scan_pulang_hilang(): void
    {
        $shift = Shift::factory()->create(['start_time' => '08:00:00', 'end_time' => '18:00:00']);
        $e = Employee::factory()->create(['pin_device' => '5', 'name' => 'Tuti', 'default_shift_id' => $shift->id]);

        $svc = app(RosterService::class);
        $svc->assign($svc->findOrCreate(2026, 11), $e, Carbon::parse('2026-11-10'), $shift->id);

        // Masuk 13:40 untuk shift 08:00, tanpa scan pulang.
        $this->scan('5', '2026-11-10 13:40:00');
        app(AttendanceComputer::class)->computeEmployee($e, Carbon::parse('2026-11-10', 'Asia/Jakarta'));

        Artisan::call('attendance:janggal', ['--from' => '2026-11-10', '--to' => '2026-11-10']);
        $out = Artisan::output();

        $this->assertStringContainsString('5 Tuti', $out);
        $this->assertStringContainsString('telat', $out);
        $this->assertStringContainsString('lupa scan pulang?', $out);
    }

    public function test_hari_bersih_tidak_ada_kejanggalan(): void
    {
        $shift = Shift::factory()->create(['start_time' => '08:00:00', 'end_time' => '18:00:00']);
        $e = Employee::factory()->create(['pin_device' => '5', 'name' => 'Tuti', 'default_shift_id' => $shift->id]);

        $svc = app(RosterService::class);
        $svc->assign($svc->findOrCreate(2026, 11), $e, Carbon::parse('2026-11-10'), $shift->id);

        $this->scan('5', '2026-11-10 07:55:00');
        $this->scan('5', '2026-11-10 18:05:00');
        app(AttendanceComputer::class)->computeEmployee($e, Carbon::parse('2026-11-10', 'Asia/Jakarta'));

        Artisan::call('attendance:janggal', ['--from' => '2026-11-10', '--to' => '2026-11-10']);

        $this->assertStringNotContainsString('Tuti', Artisan::output());
    }
}
