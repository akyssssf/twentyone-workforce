<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\Roster\RosterService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `roster:lihat` — membaca roster, bukan mengubahnya.
 *
 * Selama ini roster cuma bisa diubah dari terminal, tidak bisa dibaca. Padahal
 * pertanyaan yang paling wajar sebelum menyusun bulan berikutnya adalah "bulan
 * lalu polanya bagaimana?", dan tanpa ini jawabannya cuma bisa didapat dengan
 * memelototi kalender di layar.
 */
class LihatRosterTest extends TestCase
{
    use RefreshDatabase;

    protected Shift $pagi;

    protected Shift $malam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'Asia/Jakarta'));

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

    protected function jadwalkan(Employee $orang, string $tanggal, ?int $shiftId): void
    {
        $service = app(RosterService::class);
        $t = Carbon::parse($tanggal, 'Asia/Jakarta');

        $service->assign($service->findOrCreate((int) $t->year, (int) $t->month), $orang, $t, $shiftId);
    }

    protected function jalankan(string $argumen): string
    {
        Artisan::call('roster:lihat '.$argumen);

        return Artisan::output();
    }

    public function test_menampilkan_pola_sebulan_per_orang(): void
    {
        $orang = $this->karyawan('Kasir Uji', '99');

        $this->jadwalkan($orang, '2026-08-03', $this->pagi->id);
        $this->jadwalkan($orang, '2026-08-04', $this->malam->id);
        $this->jadwalkan($orang, '2026-08-05', null);

        $keluaran = $this->jalankan('2026-08 --pin=99');

        $this->assertStringContainsString('Kasir Uji (99)', $keluaran);
        $this->assertStringContainsString('Agustus 2026', $keluaran);

        // P pagi, S siang/malam, L libur — dan sisanya titik karena belum ada.
        $this->assertMatchesRegularExpression('/P\s+S\s+L/', $keluaran);
        $this->assertStringContainsString('·', $keluaran);
    }

    public function test_saringan_divisi(): void
    {
        $kasir = $this->karyawan('Orang Kasir', '99');
        $kasir->divisions()->attach(
            Division::where('code', 'kasir')->firstOrFail()->id,
            ['is_primary' => true],
        );

        $this->karyawan('Orang Lain', '98');

        $keluaran = $this->jalankan('2026-08 --divisi=kasir');

        $this->assertStringContainsString('Orang Kasir', $keluaran);
        $this->assertStringNotContainsString('Orang Lain', $keluaran);
    }

    public function test_bulan_ngawur_ditolak(): void
    {
        $this->artisan('roster:lihat 2026-13')->assertFailed();
        $this->artisan('roster:lihat Agustus')->assertFailed();
    }

    public function test_divisi_tidak_dikenal_ditolak(): void
    {
        $this->artisan('roster:lihat 2026-08 --divisi=ngawur')->assertFailed();
    }
}
