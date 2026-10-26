<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\Shift;
use App\Models\User;
use App\Services\Roster\RosterService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `lembur:umum` — lembur hari kerja bersama (General Cleaning) dari jam scan,
 * dengan pengali tarif.
 */
class LemburUmumTest extends TestCase
{
    use RefreshDatabase;

    protected const TANGGAL = '2026-10-27';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'Asia/Jakarta'));

        Shift::where('code', 'pagi')->update(['start_time' => '08:00:00', 'end_time' => '18:00:00']);
        Shift::where('code', 'malam')->update(['start_time' => '14:00:00', 'end_time' => '23:30:00']);

        User::factory()->create(['role' => UserRole::Admin]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function orang(string $nama, string $pin, ?string $shiftKode, array $scan): Employee
    {
        $e = Employee::factory()->create(['branch_id' => Branch::current()->id, 'name' => $nama, 'pin_device' => $pin]);

        if ($shiftKode !== null) {
            $service = app(RosterService::class);
            $service->assign($service->findOrCreate(2026, 10), $e, Carbon::parse(self::TANGGAL), Shift::where('code', $shiftKode)->value('id'));
        }

        foreach ($scan as $jam) {
            $at = Carbon::parse(self::TANGGAL.' '.$jam, 'Asia/Jakarta');
            AttendanceLog::create([
                'cloud_id' => 'UJI', 'employee_id' => $e->id, 'pin' => $pin,
                'scanned_at' => $at, 'scan_minute' => $at->copy()->startOfMinute(), 'source' => 'webhook',
            ]);
        }

        return $e;
    }

    public function test_pratinjau_menghitung_dari_scan_dan_tidak_menyimpan(): void
    {
        $this->orang('Libur Pulang', '11', null, ['08:05:00', '12:30:00']);     // libur: 08:05 → 12:30 = 4j25m
        $this->orang('Libur Lewat', '12', null, ['08:00:00', '13:40:00']);      // pulang lewat batas → dipotong 13:00 = 5j
        $this->orang('Libur Tanpa Pulang', '13', null, ['08:00:00']);           // tanpa scan pulang → sampai 13:00 = 5j
        $this->orang('Shift Malam', '14', 'malam', ['08:10:00', '13:52:00']);   // 08:10 → 14:00 = 5j50m
        $this->orang('Shift Pagi', '15', 'pagi', ['07:50:00', '18:02:00']);     // pagi biasa: tidak ada tambahan
        $this->orang('Malam Biasa', '16', 'malam', ['13:50:00']);               // masuk shiftnya sendiri: bukan lembur pagi

        $this->assertSame(0, Artisan::call('lembur:umum', ['tanggal' => self::TANGGAL]));
        $keluaran = Artisan::output();

        $this->assertStringContainsString('4j 25m', $keluaran);
        $this->assertStringContainsString('Libur Lewat', $keluaran);
        $this->assertStringContainsString('5j 50m', $keluaran);
        $this->assertStringNotContainsString('Shift Pagi ', $keluaran, 'yang pagi tidak dapat tambahan');
        $this->assertStringNotContainsString('Malam Biasa', $keluaran);
        $this->assertStringContainsString('4 orang', $keluaran);
        $this->assertStringContainsString('Belum disimpan', $keluaran);

        $this->assertSame(0, OvertimeRecord::count(), 'tanpa --simpan tidak ada yang berubah');
    }

    public function test_simpan_membuat_catatan_berpengali_dan_tidak_dobel(): void
    {
        $a = $this->orang('Libur Pulang', '11', null, ['08:05:00', '12:30:00']);

        $this->assertSame(0, Artisan::call('lembur:umum', ['tanggal' => self::TANGGAL, '--simpan' => true]));

        $r = OvertimeRecord::sole();
        $this->assertSame($a->id, $r->employee_id);
        $this->assertSame(265, (int) $r->payable_minutes, 'menit nyata, BUKAN digandakan');
        $this->assertSame(2.0, (float) $r->multiplier);
        $this->assertSame('confirmed', $r->status);
        $this->assertNotNull($r->confirmed_by, 'dilindungi dari perhitungan otomatis');
        $this->assertStringContainsString('08:05–12:30, tarif ×2', $r->note);

        // Dijalankan lagi: dilewati.
        $this->artisan('lembur:umum '.self::TANGGAL.' --simpan')
            ->expectsOutputToContain('Dilewati')
            ->assertSuccessful();
        $this->assertSame(1, OvertimeRecord::count());

        // Rekap hari itu memuat lembur nyata (265 menit), bukan 530.
        $this->assertSame(265, (int) Attendance::where('employee_id', $a->id)->sum('overtime_minutes'));
    }

    public function test_abaikan_dan_pin_menyaring_orang(): void
    {
        $this->orang('Satu', '11', null, ['08:05:00', '12:30:00']);
        $this->orang('Dua', '12', null, ['08:00:00', '12:00:00']);

        Artisan::call('lembur:umum', ['tanggal' => self::TANGGAL, '--abaikan' => ['12'], '--simpan' => true]);

        $this->assertSame(1, OvertimeRecord::count());
        $this->assertSame('Satu', OvertimeRecord::sole()->employee->name);
    }

    public function test_pengali_di_luar_batas_ditolak(): void
    {
        $this->assertSame(1, Artisan::call('lembur:umum', ['tanggal' => self::TANGGAL, '--pengali' => 9]));
    }
}
