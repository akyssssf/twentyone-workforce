<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceAdjustment;
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
 * Memaafkan PULANG CEPAT, bukan cuma telat.
 *
 * Kasus nyatanya sepele tapi sering: karyawan minta izin pulang lebih awal
 * untuk urusan keluarga dan pemilik kafe menyetujuinya. Mekanismenya sudah ada
 * di AttendanceComputer (`waive_early_leave`) sejak lama, tapi tidak pernah ada
 * perintah yang bisa membuatnya — jadi izin yang sudah disetujui tetap terpotong
 * sebagai pulang cepat, dan satu-satunya jalan keluar adalah tidak scan pulang
 * sama sekali. Itu memperbaiki potongan dengan cara merusak jam kerjanya.
 */
class MaafkanPulangCepatTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $karyawan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-09 12:00:00', 'Asia/Jakarta'));

        Shift::where('code', 'pagi')->update(['start_time' => '08:00:00', 'end_time' => '18:00:00']);
        $pagi = Shift::where('code', 'pagi')->firstOrFail();

        $this->karyawan = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Izin Pulang',
            'pin_device' => '41',
            'default_shift_id' => $pagi->id,
        ]);

        $service = app(RosterService::class);
        $service->assign(
            $service->findOrCreate(2026, 9),
            $this->karyawan,
            Carbon::parse('2026-09-05', 'Asia/Jakarta'),
            $pagi->id,
        );

        // Datang tepat waktu, pulang jam 3 sore — tiga jam sebelum jadwal.
        foreach (['08:01:00', '15:02:00'] as $jam) {
            $at = Carbon::parse("2026-09-05 {$jam}", 'Asia/Jakarta');

            AttendanceLog::create([
                'cloud_id' => 'UJI',
                'employee_id' => $this->karyawan->id,
                'pin' => '41',
                'scanned_at' => $at,
                'scan_minute' => $at->copy()->startOfMinute(),
                'source' => 'webhook',
            ]);
        }

        Artisan::call('attendance:compute', ['--from' => '2026-09-05', '--to' => '2026-09-05']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Catat jam pulang yang sebenarnya.
     *
     * Perlu karena scan pulang cuma ditangkap kalau jaraknya ≤60 menit dari jam
     * pulang terjadwal (checkout_capture_minutes). Pulang tiga jam lebih awal
     * karena itu TIDAK menghasilkan potongan apa pun — jam pulangnya dianggap
     * tidak ada, dan jam kerjanya jadi nol. Jadi urutan yang benar memang dua
     * langkah: catat jam pulangnya dulu, baru maafkan pulang cepatnya.
     */
    protected function catatJamPulang(): void
    {
        Artisan::call('attendance:jam', [
            'pin' => '41', 'tanggal' => '2026-09-05',
            '--pulang' => '15:02', '--alasan' => 'Izin pulang awal, disetujui pemilik',
        ]);
    }

    protected function rekap(): ?Attendance
    {
        return Attendance::where('employee_id', $this->karyawan->id)
            ->whereDate('work_date', Carbon::parse('2026-09-05'))
            ->first();
    }

    public function test_pulang_cepat_bisa_dimaafkan(): void
    {
        $this->catatJamPulang();
        $this->assertGreaterThan(0, (int) $this->rekap()?->early_leave_minutes);

        $this->artisan('attendance:waive-late 41 2026-09-05 --pulang-cepat --alasan="Izin acara keluarga, disetujui pemilik"')
            ->assertSuccessful();

        $this->assertSame(0, (int) $this->rekap()?->early_leave_minutes);
    }

    /** Memaafkan pulang cepat TIDAK boleh ikut memaafkan telat. */
    public function test_pulang_cepat_saja_tidak_menyentuh_telat(): void
    {
        $this->artisan('attendance:waive-late 41 2026-09-05 --pulang-cepat --alasan="Izin acara keluarga"');

        $this->assertSame(1, AttendanceAdjustment::where('type', 'waive_early_leave')->whereNull('reverted_by_id')->count());
        $this->assertSame(0, AttendanceAdjustment::where('type', 'waive_late')->whereNull('reverted_by_id')->count());
    }

    public function test_keduanya_memaafkan_telat_dan_pulang_cepat(): void
    {
        $this->catatJamPulang();
        $this->artisan('attendance:waive-late 41 2026-09-05 --keduanya --alasan="Izin datang telat dan pulang cepat"')
            ->assertSuccessful();

        $this->assertSame(1, AttendanceAdjustment::where('type', 'waive_late')->whereNull('reverted_by_id')->count());
        $this->assertSame(1, AttendanceAdjustment::where('type', 'waive_early_leave')->whereNull('reverted_by_id')->count());
        $this->assertSame(0, (int) $this->rekap()?->early_leave_minutes);
    }

    /** Perilaku lama tidak boleh berubah: tanpa opsi berarti telat saja. */
    public function test_tanpa_opsi_tetap_memaafkan_telat_saja(): void
    {
        $this->catatJamPulang();
        $this->artisan('attendance:waive-late 41 2026-09-05 --alasan="Telat saja"');

        $this->assertSame(1, AttendanceAdjustment::where('type', 'waive_late')->whereNull('reverted_by_id')->count());
        $this->assertSame(0, AttendanceAdjustment::where('type', 'waive_early_leave')->count());

        // Pulang cepatnya tetap terhitung, karena memang tidak diminta dimaafkan.
        $this->assertGreaterThan(0, (int) $this->rekap()?->early_leave_minutes);
    }

    public function test_koreksi_bertahan_setelah_dihitung_ulang(): void
    {
        $this->catatJamPulang();
        $this->artisan('attendance:waive-late 41 2026-09-05 --pulang-cepat --alasan="Izin acara keluarga"');

        Artisan::call('attendance:compute', ['--from' => '2026-09-05', '--to' => '2026-09-05']);

        $this->assertSame(0, (int) $this->rekap()?->early_leave_minutes);
    }

    public function test_bisa_dibatalkan(): void
    {
        $this->catatJamPulang();
        $this->artisan('attendance:waive-late 41 2026-09-05 --pulang-cepat --alasan="Izin acara keluarga"');
        $this->assertSame(0, (int) $this->rekap()?->early_leave_minutes);

        $this->artisan('attendance:waive-late 41 2026-09-05 --pulang-cepat --batal')->assertSuccessful();

        $this->assertGreaterThan(0, (int) $this->rekap()?->early_leave_minutes);
    }

    /**
     * Temuan yang layak dikunci: pulang jauh lebih awal TIDAK menghasilkan
     * potongan apa pun, karena scan pulang cuma ditangkap dalam 60 menit
     * terakhir sebelum jam pulang. Yang rusak justru jam kerjanya — terbaca nol.
     * Jadi izin pulang awal yang tidak dicatat bukan "aman", tapi menghilangkan
     * seluruh jam kerja hari itu.
     */
    public function test_pulang_jauh_lebih_awal_tidak_tertangkap_sebagai_jam_pulang(): void
    {
        $this->assertNull($this->rekap()?->check_out_at);
        $this->assertSame(0, (int) $this->rekap()?->early_leave_minutes);
        $this->assertSame(0, (int) $this->rekap()?->work_minutes);

        $this->catatJamPulang();

        $this->assertSame('15:02', $this->rekap()?->check_out_at?->format('H:i'));
        $this->assertGreaterThan(0, (int) $this->rekap()?->work_minutes);
    }

    public function test_tanpa_alasan_ditolak(): void
    {
        $this->artisan('attendance:waive-late 41 2026-09-05 --pulang-cepat')->assertFailed();
    }
}
