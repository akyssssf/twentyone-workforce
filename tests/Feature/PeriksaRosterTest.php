<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Services\Roster\RosterService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `roster:periksa` — menjalankan pemeriksa roster dari terminal.
 *
 * RosterValidator sudah lama ada dan sudah memeriksa hal yang paling mahal —
 * "punya dua shift yang bertabrakan pada tanggal X", persis kasus baris dobel
 * yang diam-diam merebut scan lalu menghasilkan telat berjam-jam palsu. Yang
 * tidak pernah ada adalah cara MENJALANKANNYA setelah roster terbit, padahal
 * roster berubah terus lewat tukar shift dan pengganti cuti.
 */
class PeriksaRosterTest extends TestCase
{
    use RefreshDatabase;

    protected Shift $pagi;

    protected Shift $malam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'Asia/Jakarta'));

        Shift::where('code', 'pagi')->update(['start_time' => '08:00:00', 'end_time' => '18:00:00']);
        Shift::where('code', 'malam')->update(['start_time' => '14:00:00', 'end_time' => '01:00:00']);

        $this->pagi = Shift::where('code', 'pagi')->firstOrFail();
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
            'default_shift_id' => $this->pagi->id,
        ]);
    }

    protected function jadwalkan(Employee $orang, string $tanggal, Shift $shift): RosterAssignment
    {
        $service = app(RosterService::class);

        return $service->assign(
            $service->findOrCreate(2026, 9),
            $orang,
            Carbon::parse($tanggal, 'Asia/Jakarta'),
            $shift->id,
        );
    }

    protected function jalankan(string $bulan = '2026-09'): string
    {
        Artisan::call('roster:periksa', ['bulan' => $bulan]);

        return Artisan::output();
    }

    public function test_bulan_tanpa_roster_ditolak(): void
    {
        $this->artisan('roster:periksa 2026-09')->assertFailed();
        $this->assertStringContainsString('Belum ada roster', $this->jalankan());
    }

    public function test_format_bulan_ngawur_ditolak(): void
    {
        $this->artisan('roster:periksa September')->assertFailed();
        $this->artisan('roster:periksa 2026-13')->assertFailed();
    }

    /** Yang paling mahal: dua shift bertabrakan di tanggal yang sama. */
    public function test_menemukan_dua_shift_yang_bertabrakan(): void
    {
        $orang = $this->karyawan('Dobel Shift', '95');
        $baris = $this->jadwalkan($orang, '2026-09-03', $this->pagi);

        RosterAssignment::create([
            'roster_id' => $baris->roster_id,
            'employee_id' => $orang->id,
            'work_date' => Carbon::parse('2026-09-03', 'Asia/Jakarta')->startOfDay(),
            'shift_id' => $this->malam->id,
            'status' => AssignmentStatus::Scheduled,
            'source' => 'swap',
        ]);

        $keluaran = $this->jalankan();

        $this->assertStringContainsString('Dobel Shift', $keluaran);
        $this->artisan('roster:periksa 2026-09')->assertFailed();
    }

    /**
     * Karyawan tanpa baris roster sama sekali: ketidakhadirannya tidak pernah
     * terhitung alpha, jadi tidak akan pernah kelihatan oleh siapa pun.
     */
    public function test_menyebutkan_karyawan_yang_belum_dijadwalkan(): void
    {
        $terjadwal = $this->karyawan('Sudah Dijadwalkan', '96');
        $this->jadwalkan($terjadwal, '2026-09-03', $this->pagi);

        $this->karyawan('Belum Dijadwalkan', '97');

        $keluaran = $this->jalankan();

        $this->assertStringContainsString('BELUM DIJADWALKAN', $keluaran);
        $this->assertStringContainsString('Belum Dijadwalkan', $keluaran);
    }

    /** Orang yang bergabung setelah bulan itu bukan kelalaian. */
    public function test_karyawan_yang_belum_bergabung_tidak_dilaporkan(): void
    {
        $terjadwal = $this->karyawan('Sudah Dijadwalkan', '96');
        $this->jadwalkan($terjadwal, '2026-09-03', $this->pagi);

        Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Baru Masuk Oktober',
            'pin_device' => '98',
            'default_shift_id' => $this->pagi->id,
            'joined_at' => Carbon::parse('2026-10-05', 'Asia/Jakarta'),
        ]);

        $this->assertStringNotContainsString('Baru Masuk Oktober', $this->jalankan());
    }
}
