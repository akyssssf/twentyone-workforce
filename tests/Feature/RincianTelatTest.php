<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Shift;
use App\Support\Settings;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** `attendance:telat` — rincian telat per orang dan estimasi potongannya. */
class RincianTelatTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $budi;

    protected Shift $pagi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-18 12:00:00', 'Asia/Jakarta'));
        Settings::put('attendance.late_tolerance_minutes', 10);

        $this->pagi = Shift::where('code', 'pagi')->firstOrFail();
        $this->budi = Employee::factory()->create(['branch_id' => Branch::current()->id, 'name' => 'Budi Uji', 'pin_device' => '31']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function telat(string $tanggal, int $detik): void
    {
        $masuk = Carbon::parse("{$tanggal} 08:00:00", 'Asia/Jakarta')->addSeconds($detik);

        Attendance::create([
            'employee_id' => $this->budi->id, 'shift_id' => $this->pagi->id,
            'work_date' => Carbon::parse($tanggal),
            'scheduled_in' => Carbon::parse("{$tanggal} 08:00:00", 'Asia/Jakarta'),
            'check_in_at' => $masuk,
            'late_seconds' => $detik,
            'late_minutes' => $detik > 600 ? (int) ceil($detik / 60) : 0,
            'status' => AttendanceStatus::Hadir,
            'computed_at' => now(),
        ]);
    }

    public function test_rincian_per_orang_dengan_potongan_dan_toleransi_terpisah(): void
    {
        $this->telat('2026-10-05', 25 * 60);   // dihitung: 25 menit
        $this->telat('2026-10-06', 7 * 60);    // dalam toleransi
        $this->telat('2026-10-07', 12 * 60);   // dihitung: 12 menit

        $this->assertSame(0, Artisan::call('attendance:telat', ['--from' => '2026-10-01', '--to' => '2026-10-18']));
        $keluaran = Artisan::output();

        $this->assertStringContainsString('2 kejadian dihitung, 1 dalam toleransi 10 menit', $keluaran);
        $this->assertStringContainsString('Budi Uji (PIN 31', $keluaran);
        $this->assertStringContainsString('08:25:00', $keluaran);
        $this->assertStringContainsString('2x telat · total 37m', $keluaran);
        $this->assertStringContainsString('Ringkasan', $keluaran);

        // Yang dalam toleransi tidak ikut tabel orangnya.
        $this->assertStringNotContainsString('08:07:00', $keluaran);
    }

    public function test_tanpa_telat_dilaporkan_bersih(): void
    {
        $this->assertSame(0, Artisan::call('attendance:telat', ['--from' => '2026-10-01', '--to' => '2026-10-18']));

        $this->assertStringContainsString('Tidak ada telat yang dihitung', Artisan::output());
    }

    public function test_pin_menyaring_satu_orang(): void
    {
        $this->telat('2026-10-05', 25 * 60);

        Artisan::call('attendance:telat', ['--pin' => '999', '--from' => '2026-10-01', '--to' => '2026-10-18']);

        $this->assertStringContainsString('Tidak ada telat yang dihitung', Artisan::output());
    }
}
