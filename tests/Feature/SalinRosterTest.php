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
use Tests\TestCase;

/**
 * `roster:salin` — "bulan depan ulangi pola bulan ini", dari data asli.
 *
 * Yang dijaga: geserannya per hari (Senin ke Senin kalau 28 hari), libur ikut
 * tersalin karena itu bagian pola, dan cuti TIDAK tersalin karena itu keputusan
 * untuk tanggal tertentu — menyalinnya berarti mencutikan orang bulan depan
 * tanpa ada yang mengajukan.
 */
class SalinRosterTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $dea;

    protected Shift $pagi;

    protected Shift $malam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', 'Asia/Jakarta'));

        $this->pagi = Shift::where('code', 'pagi')->firstOrFail();
        $this->malam = Shift::where('code', 'malam')->firstOrFail();

        $this->dea = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Dea Uji',
            'pin_device' => '20',
            'default_shift_id' => $this->malam->id,
        ]);

        // Sumber: Kamis 3 Sep pagi, Jumat 4 Sep malam, Sabtu 5 Sep libur.
        $service = app(RosterService::class);
        $roster = $service->findOrCreate(2026, 9);

        $service->assign($roster, $this->dea, Carbon::parse('2026-09-03', 'Asia/Jakarta'), $this->pagi->id);
        $service->assign($roster, $this->dea, Carbon::parse('2026-09-04', 'Asia/Jakarta'), $this->malam->id);
        $service->assign($roster, $this->dea, Carbon::parse('2026-09-05', 'Asia/Jakarta'), null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function baris(string $tanggal): ?RosterAssignment
    {
        return RosterAssignment::where('employee_id', $this->dea->id)
            ->whereDate('work_date', Carbon::parse($tanggal))
            ->first();
    }

    /** 28 hari: Kamis 3 Sep → Kamis 1 Okt. */
    public function test_menyalin_digeser_per_hari_termasuk_libur(): void
    {
        $this->artisan('roster:salin --dari=2026-09-03..2026-09-05 --ke=2026-10-01 --ya')
            ->assertSuccessful();

        $this->assertSame($this->pagi->id, $this->baris('2026-10-01')?->shift_id);
        $this->assertSame($this->malam->id, $this->baris('2026-10-02')?->shift_id);

        $libur = $this->baris('2026-10-03');
        $this->assertNotNull($libur);
        $this->assertNull($libur->shift_id);
        $this->assertSame(AssignmentStatus::Off, $libur->status);
    }

    /** Cuti bukan pola. Menyalinnya = mencutikan orang bulan depan. */
    public function test_cuti_tidak_ikut_tersalin(): void
    {
        $this->baris('2026-09-04')->update(['status' => AssignmentStatus::Leave]);

        $this->artisan('roster:salin --dari=2026-09-03..2026-09-05 --ke=2026-10-01 --ya')
            ->assertSuccessful()
            ->expectsOutputToContain('1 baris cuti/batal dilewati');

        $this->assertNull($this->baris('2026-10-02'));
    }

    public function test_sumber_tidak_tersentuh(): void
    {
        $this->artisan('roster:salin --dari=2026-09-03..2026-09-05 --ke=2026-10-01 --ya');

        $this->assertSame($this->pagi->id, $this->baris('2026-09-03')?->shift_id);
        $this->assertSame($this->malam->id, $this->baris('2026-09-04')?->shift_id);
    }

    public function test_geseran_bukan_kelipatan_tujuh_diperingatkan(): void
    {
        $this->artisan('roster:salin --dari=2026-09-03..2026-09-05 --ke=2026-10-02 --ya')
            ->expectsOutputToContain('bukan kelipatan 7');
    }

    public function test_menolak_konfirmasi_tidak_menyalin(): void
    {
        $this->artisan('roster:salin --dari=2026-09-03..2026-09-05 --ke=2026-10-01')
            ->expectsConfirmation('Lanjutkan?', 'no')
            ->assertSuccessful();

        $this->assertNull($this->baris('2026-10-01'));
    }

    public function test_tujuan_menimpa_sumber_ditolak(): void
    {
        $this->artisan('roster:salin --dari=2026-09-03..2026-09-05 --ke=2026-09-04 --ya')->assertFailed();
    }

    public function test_tanpa_argumen_ditolak(): void
    {
        $this->artisan('roster:salin --ke=2026-10-01')->assertFailed();
        $this->artisan('roster:salin --dari=2026-09-03 --ke=2026-10-01')->assertFailed();
    }
}
