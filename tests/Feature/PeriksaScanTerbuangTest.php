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

/**
 * `attendance:periksa` — sapu semua orang, cari jam masuk yang bukan scan pertama.
 *
 * Ada karena `attendance:jelaskan` baru berguna kalau sudah tahu SIAPA yang
 * bermasalah, sementara kegagalannya sendiri tidak menimbulkan error dan tidak
 * kelihatan di rekap. Tanpa penyapu, yang ketahuan cuma karyawan yang kebetulan
 * memeriksa rekapnya sendiri lalu protes.
 */
class PeriksaScanTerbuangTest extends TestCase
{
    use RefreshDatabase;

    protected Shift $malam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);

        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00', 'Asia/Jakarta'));

        $this->malam = Shift::where('code', 'malam')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function karyawan(string $nama, string $pin): Employee
    {
        return Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => $nama,
            'pin_device' => $pin,
            'default_shift_id' => $this->malam->id,
        ]);
    }

    protected function jadwalkan(Employee $orang, string $tanggal): void
    {
        $service = app(RosterService::class);

        $service->assign(
            $service->findOrCreate(2026, 8),
            $orang,
            Carbon::parse($tanggal, 'Asia/Jakarta'),
            $this->malam->id,
        );
    }

    protected function scan(Employee $orang, string $waktu): void
    {
        $at = Carbon::parse($waktu, 'Asia/Jakarta');

        AttendanceLog::create([
            'cloud_id' => 'UJI',
            'employee_id' => $orang->id,
            'pin' => $orang->pin_device,
            'scanned_at' => $at,
            'scan_minute' => $at->copy()->startOfMinute(),
            'source' => 'webhook',
        ]);
    }

    protected function periksa(string $tanggal = '2026-08-21'): string
    {
        Artisan::call('attendance:compute', ['--from' => $tanggal, '--to' => $tanggal]);
        Artisan::call('attendance:periksa', ['--from' => $tanggal]);

        return Artisan::output();
    }

    /** Kasus 21 Agustus: jam khusus memundurkan jendela, scan pagi terbuang. */
    public function test_menemukan_scan_pertama_yang_terbuang_di_luar_jendela(): void
    {
        $korban = $this->karyawan('Korban Jendela', '71');
        $this->jadwalkan($korban, '2026-08-21');

        Artisan::call('roster:jam-khusus', [
            'tanggal' => '2026-08-21', 'shift' => 'malam',
            'mulai' => '13:30', 'selesai' => '22:30',
        ]);

        $this->scan($korban, '2026-08-21 07:54:07');
        $this->scan($korban, '2026-08-21 13:00:12');

        $keluaran = $this->periksa();

        $this->assertStringContainsString('Korban Jendela', $keluaran);
        $this->assertStringContainsString('07:54:07', $keluaran);
        $this->assertStringContainsString('13:00:12', $keluaran);
        $this->assertStringContainsString('di luar jendela', $keluaran);
        $this->assertStringContainsString('1 rekap yang jam masuknya bukan scan pertama', $keluaran);
    }

    /** Yang jam masuknya memang scan pertamanya tidak boleh ikut dilaporkan. */
    public function test_yang_normal_tidak_dilaporkan(): void
    {
        $normal = $this->karyawan('Normal Saja', '72');
        $this->jadwalkan($normal, '2026-08-21');

        $this->scan($normal, '2026-08-21 16:50:00');
        $this->scan($normal, '2026-08-22 00:55:00');

        $keluaran = $this->periksa();

        $this->assertStringContainsString('Tidak ada temuan', $keluaran);
        $this->assertStringNotContainsString('Normal Saja', $keluaran);
    }

    /** Satu orang bermasalah tidak boleh menyeret yang lain ikut terlapor. */
    public function test_hanya_yang_bermasalah_yang_muncul(): void
    {
        $korban = $this->karyawan('Korban Jendela', '71');
        $normal = $this->karyawan('Normal Saja', '72');

        $this->jadwalkan($korban, '2026-08-21');
        $this->jadwalkan($normal, '2026-08-21');

        Artisan::call('roster:jam-khusus', [
            'tanggal' => '2026-08-21', 'shift' => 'malam',
            'mulai' => '13:30', 'selesai' => '22:30',
        ]);

        $this->scan($korban, '2026-08-21 07:54:07');
        $this->scan($korban, '2026-08-21 13:00:12');
        $this->scan($normal, '2026-08-21 13:25:00');

        $keluaran = $this->periksa();

        $this->assertStringContainsString('Korban Jendela', $keluaran);
        $this->assertStringNotContainsString('Normal Saja', $keluaran);
    }

    /**
     * Regresi dari kejadian nyata: penyapu ini pernah melaporkan 57 rekap
     * bermasalah dalam seminggu, dan SEMUANYA salah — yang dikira "scan datang
     * pagi yang terbuang" ternyata scan PULANG shift malam hari sebelumnya,
     * yang memang jatuh sekitar 01:00. Hari operasional baru berganti jam
     * 06:00, jadi scan 01:00 itu milik tanggal kemarin, bukan hari ini.
     *
     * Diagnosis yang salah lebih berbahaya daripada tidak ada diagnosis: laporan
     * itu nyaris dipakai untuk membetulkan roster satu minggu penuh yang
     * sebenarnya sudah benar.
     */
    public function test_scan_pulang_shift_malam_kemarin_bukan_scan_pertama_hari_ini(): void
    {
        $orang = $this->karyawan('Pulang Dini Hari', '73');

        $this->jadwalkan($orang, '2026-08-20');
        $this->jadwalkan($orang, '2026-08-21');

        // Shift malam 20 Agustus: datang 16:50, pulang 01:00 tanggal 21.
        $this->scan($orang, '2026-08-20 16:50:00');
        $this->scan($orang, '2026-08-21 01:00:37');

        // Shift malam 21 Agustus: datang 16:45.
        $this->scan($orang, '2026-08-21 16:45:00');

        $this->assertStringContainsString('Tidak ada temuan', $this->periksa('2026-08-21'));
    }

    /** Datang lebih awal dari ambang hari operasional tetap harus terlihat. */
    public function test_scan_sebelum_jam_enam_tetap_dihitung_kalau_masih_di_jendela(): void
    {
        $pagi = Shift::where('code', 'pagi')->firstOrFail();
        $orang = $this->karyawan('Datang Subuh', '74');

        $service = app(RosterService::class);
        $service->assign(
            $service->findOrCreate(2026, 8),
            $orang,
            Carbon::parse('2026-08-21', 'Asia/Jakarta'),
            $pagi->id,
        );

        // Jendela shift pagi (master 09:00) dibuka 05:00, jadi 05:30 masih sah
        // sebagai jam masuk walaupun hari operasional baru mulai 06:00.
        $this->scan($orang, '2026-08-21 05:30:00');

        $this->assertStringContainsString('Tidak ada temuan', $this->periksa('2026-08-21'));

        Artisan::call('attendance:jelaskan', ['pin' => '74', 'tanggal' => '2026-08-21']);
        $this->assertStringContainsString('05:30:00', Artisan::output());
    }

    public function test_rentang_terbalik_ditolak(): void
    {
        $this->artisan('attendance:periksa --from=2026-08-21 --to=2026-08-20')->assertFailed();
    }
}
