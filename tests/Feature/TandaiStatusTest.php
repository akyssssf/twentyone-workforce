<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\AttendanceAdjustment;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\Roster\RosterService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `attendance:tandai` — ubah status kehadiran satu hari.
 *
 * Ditulis sebagai koreksi, bukan dengan mengubah baris attendances: cron
 * menghitung ulang dua hari terakhir tiap 15 menit, jadi perubahan langsung
 * akan hilang tanpa ada yang sadar. Itu yang dijaga paling keras di sini.
 */
class TandaiStatusTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $karyawan;

    protected Shift $pagi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);

        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00', 'Asia/Jakarta'));

        $this->pagi = Shift::where('code', 'pagi')->firstOrFail();

        $this->karyawan = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Uji Tandai',
            'pin_device' => '66',
            'default_shift_id' => $this->pagi->id,
        ]);

        // Terjadwal tapi tidak scan sama sekali = Alpha. Ini titik awalnya.
        $service = app(RosterService::class);
        $service->assign(
            $service->findOrCreate(2026, 8),
            $this->karyawan,
            Carbon::parse('2026-08-23', 'Asia/Jakarta'),
            $this->pagi->id,
        );

        $this->artisan('attendance:compute --from=2026-08-23 --to=2026-08-23');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function statusRekap(): ?AttendanceStatus
    {
        return Attendance::where('employee_id', $this->karyawan->id)
            ->whereDate('work_date', Carbon::parse('2026-08-23'))
            ->first()?->status;
    }

    public function test_alpha_bisa_ditandai_sakit(): void
    {
        $this->assertSame(AttendanceStatus::Alpha, $this->statusRekap());

        $this->artisan('attendance:tandai 66 2026-08-23 sakit --alasan="Ada surat dokter"')
            ->assertSuccessful();

        $this->assertSame(AttendanceStatus::Sakit, $this->statusRekap());
    }

    /** Yang paling penting: koreksi harus bertahan melewati hitung ulang. */
    public function test_penandaan_bertahan_setelah_dihitung_ulang(): void
    {
        $this->artisan('attendance:tandai 66 2026-08-23 sakit --alasan="Ada surat dokter"');

        $this->artisan('attendance:compute --from=2026-08-23 --to=2026-08-23');

        $this->assertSame(AttendanceStatus::Sakit, $this->statusRekap());
    }

    public function test_bisa_dibatalkan_dan_kembali_alpha(): void
    {
        $this->artisan('attendance:tandai 66 2026-08-23 sakit --alasan="Ada surat dokter"');
        $this->assertSame(AttendanceStatus::Sakit, $this->statusRekap());

        $this->artisan('attendance:tandai 66 2026-08-23 sakit --batal')->assertSuccessful();

        $this->assertSame(AttendanceStatus::Alpha, $this->statusRekap());
    }

    /**
     * Menandai ulang tidak boleh meninggalkan dua koreksi aktif — kalau
     * menumpuk, hasilnya bergantung urutan baris dan tidak bisa ditebak.
     */
    public function test_menandai_ulang_membatalkan_yang_lama(): void
    {
        $this->artisan('attendance:tandai 66 2026-08-23 sakit --alasan="Kata temannya sakit"');
        $this->artisan('attendance:tandai 66 2026-08-23 izin --alasan="Ternyata izin keluarga"');

        $aktif = AttendanceAdjustment::where('employee_id', $this->karyawan->id)
            ->where('type', 'set_status')
            ->whereNull('reverted_by_id')
            ->get();

        $this->assertCount(1, $aktif);
        $this->assertSame('izin', $aktif->first()->value_status);
        $this->assertSame(AttendanceStatus::Izin, $this->statusRekap());
    }

    /** Jejaknya append-only: yang dibatalkan tidak dihapus. */
    public function test_koreksi_lama_tidak_dihapus_hanya_ditandai_batal(): void
    {
        $this->artisan('attendance:tandai 66 2026-08-23 sakit --alasan="Kata temannya sakit"');
        $this->artisan('attendance:tandai 66 2026-08-23 izin --alasan="Ternyata izin keluarga"');

        $this->assertSame(2, AttendanceAdjustment::where('type', 'set_status')->count());
    }

    public function test_tanpa_alasan_ditolak(): void
    {
        $this->artisan('attendance:tandai 66 2026-08-23 sakit')->assertFailed();

        $this->assertSame(AttendanceStatus::Alpha, $this->statusRekap());
    }

    public function test_status_ngawur_ditolak(): void
    {
        $this->artisan('attendance:tandai 66 2026-08-23 bolos --alasan="apa saja"')->assertFailed();
    }

    public function test_pin_tidak_dikenal_ditolak(): void
    {
        $this->artisan('attendance:tandai 999 2026-08-23 sakit --alasan="apa saja"')->assertFailed();
    }
}
