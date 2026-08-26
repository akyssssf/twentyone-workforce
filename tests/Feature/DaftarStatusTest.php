<?php

namespace Tests\Feature;

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
 * `attendance:daftar` — siapa saja yang berstatus tertentu, dan kapan.
 *
 * Angka ringkasan di web ("1 alpha") tidak menyebutkan siapa dan kapan. Tanpa
 * daftar ini, satu-satunya cara mencarinya adalah memindai rekap bulanan dengan
 * mata — dan itu sudah pernah membuat status yang SUDAH dibetulkan tetap
 * dikira bermasalah, karena angkanya ternyata milik orang lain di tanggal lain.
 */
class DaftarStatusTest extends TestCase
{
    use RefreshDatabase;

    protected Shift $pagi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);

        Carbon::setTestNow(Carbon::parse('2026-08-30 12:00:00', 'Asia/Jakarta'));

        $this->pagi = Shift::where('code', 'pagi')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Terjadwal tapi tidak scan sama sekali = Alpha. */
    protected function alpha(string $nama, string $pin, string $tanggal): Employee
    {
        $orang = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => $nama,
            'pin_device' => $pin,
            'default_shift_id' => $this->pagi->id,
        ]);

        $service = app(RosterService::class);
        $service->assign(
            $service->findOrCreate(2026, 8),
            $orang,
            Carbon::parse($tanggal, 'Asia/Jakarta'),
            $this->pagi->id,
        );

        Artisan::call('attendance:compute', ['--from' => $tanggal, '--to' => $tanggal]);

        return $orang;
    }

    protected function jalankan(string $argumen): string
    {
        Artisan::call('attendance:daftar '.$argumen);

        return Artisan::output();
    }

    public function test_menyebutkan_siapa_dan_kapan(): void
    {
        $this->alpha('Si Alpha', '51', '2026-08-23');

        $keluaran = $this->jalankan('--from=2026-08-23 --to=2026-08-23');

        $this->assertStringContainsString('Si Alpha', $keluaran);
        $this->assertStringContainsString('2026-08-23', $keluaran);
        $this->assertStringContainsString('51', $keluaran);
    }

    /** Status yang sudah dikoreksi tidak boleh ikut terdaftar sebagai alpha. */
    public function test_yang_sudah_ditandai_sakit_hilang_dari_daftar_alpha(): void
    {
        $this->alpha('Si Alpha', '51', '2026-08-23');

        Artisan::call('attendance:tandai', [
            'pin' => '51', 'tanggal' => '2026-08-23', 'status' => 'sakit',
            '--alasan' => 'Ada surat dokter',
        ]);

        $this->assertStringContainsString('Tidak ada yang berstatus',
            $this->jalankan('--from=2026-08-23 --to=2026-08-23'));

        $this->assertStringContainsString('Si Alpha',
            $this->jalankan('--status=sakit --from=2026-08-23 --to=2026-08-23'));
    }

    /**
     * work_date tersimpan sebagai "Y-m-d 00:00:00", jadi rentang yang batas
     * atasnya string tanggal pendek MEMBUANG tanggal terakhirnya. Jebakan ini
     * sudah menggigit tiga kali di proyek ini.
     */
    public function test_tanggal_terakhir_rentang_tidak_terbuang(): void
    {
        $this->alpha('Alpha Ujung', '52', '2026-08-25');

        $this->assertStringContainsString('Alpha Ujung',
            $this->jalankan('--from=2026-08-23 --to=2026-08-25'));
    }

    public function test_di_luar_rentang_tidak_ikut(): void
    {
        $this->alpha('Alpha Luar', '53', '2026-08-20');

        $this->assertStringContainsString('Tidak ada yang berstatus',
            $this->jalankan('--from=2026-08-23 --to=2026-08-25'));
    }

    public function test_status_ngawur_ditolak(): void
    {
        $this->artisan('attendance:daftar --status=bolos')->assertFailed();
    }

    public function test_rentang_terbalik_ditolak(): void
    {
        $this->artisan('attendance:daftar --from=2026-08-25 --to=2026-08-23')->assertFailed();
    }
}
