<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Division;
use App\Models\Employee;
use App\Models\SalaryComponent;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `gaji:atur` — gaji pokok per divisi/orang, berlaku mulai tanggal tertentu.
 */
class AturGajiTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $sigit;

    protected Employee $zahra;

    protected Employee $dea;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-22 10:00:00', 'Asia/Jakarta'));

        $barista = Division::where('code', 'barista')->firstOrFail();
        $kasir = Division::where('code', 'kasir')->firstOrFail();
        $waiter = Division::where('code', 'waiter')->firstOrFail();

        $this->sigit = $this->karyawan('Sigit Uji', '4', $barista);
        $this->zahra = $this->karyawan('Zahra Uji', '17', $barista);
        $this->dea = $this->karyawan('Dea Uji', '20', $kasir);

        // Divisi sekunder tidak boleh ikut: Dea juga tercatat di Waiters.
        $this->dea->divisions()->attach($waiter->id, ['is_primary' => false, 'competency_level' => 1]);

        // Gaji lama placeholder dari awal tahun.
        foreach ([$this->sigit, $this->zahra] as $e) {
            $e->salaries()->create([
                'salary_component_id' => SalaryComponent::where('code', 'gaji_pokok')->value('id'),
                'amount' => 4_000_000,
                'effective_from' => '2026-01-01',
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function karyawan(string $nama, string $pin, Division $divisi): Employee
    {
        $e = Employee::factory()->create(['branch_id' => Branch::current()->id, 'name' => $nama, 'pin_device' => $pin]);
        $e->divisions()->attach($divisi->id, ['is_primary' => true, 'competency_level' => 3]);

        return $e;
    }

    public function test_per_divisi_berlaku_surut_dan_menutup_gaji_lama(): void
    {
        $this->artisan('gaji:atur --divisi=barista --jumlah=3000000 --dari=2026-08-21 --ya')
            ->assertSuccessful();

        foreach ([$this->sigit, $this->zahra] as $e) {
            $this->assertSame(3_000_000, $e->baseSalaryOn(Carbon::parse('2026-09-20')), $e->name);
            $this->assertSame(3_000_000, $e->baseSalaryOn(Carbon::parse('2026-08-21')));
            $this->assertSame(4_000_000, $e->baseSalaryOn(Carbon::parse('2026-08-20')), 'gaji lama tetap berlaku sebelum tanggal mulai');
        }

        $this->assertSame(0, $this->dea->baseSalaryOn(Carbon::parse('2026-09-20')), 'kasir tidak tersentuh');
    }

    /** Urutan pemakaian sebenarnya: divisi dulu, lalu pengecualian per PIN di tanggal yang sama. */
    public function test_pin_menimpa_baris_di_tanggal_yang_sama(): void
    {
        $this->artisan('gaji:atur --divisi=barista --jumlah=3000000 --dari=2026-08-21 --ya')->assertSuccessful();
        $this->artisan('gaji:atur --pin=17 --jumlah=2500000 --dari=2026-08-21 --ya')->assertSuccessful();

        $this->assertSame(2_500_000, $this->zahra->baseSalaryOn(Carbon::parse('2026-09-20')));
        $this->assertSame(3_000_000, $this->sigit->baseSalaryOn(Carbon::parse('2026-09-20')));

        // Tidak ada dua baris terbuka untuk Zahra.
        $this->assertSame(1, $this->zahra->salaries()->whereNull('effective_to')->count());
    }

    public function test_divisi_sekunder_tidak_ikut(): void
    {
        // Satu-satunya anggota Waiters di sini adalah Dea sebagai divisi
        // sekunder, jadi tidak ada sasaran — dan itu ditolak, bukan diam.
        $this->artisan('gaji:atur --divisi=waiter --jumlah=2200000 --dari=2026-08-21 --ya')->assertFailed();

        $this->assertSame(0, $this->dea->baseSalaryOn(Carbon::parse('2026-09-20')), 'Dea utamanya Kasir');
    }

    public function test_menolak_kalau_ada_baris_gaji_di_masa_depan(): void
    {
        $this->sigit->salaries()->create([
            'salary_component_id' => SalaryComponent::where('code', 'gaji_pokok')->value('id'),
            'amount' => 3_500_000,
            'effective_from' => '2026-10-01',
        ]);

        $this->assertSame(1, Artisan::call('gaji:atur --divisi=barista --jumlah=3000000 --dari=2026-08-21 --ya'));

        $keluaran = Artisan::output();
        $this->assertStringContainsString('Sigit Uji', $keluaran);
        $this->assertStringContainsString('Rp 3.500.000 mulai 2026-10-01', $keluaran, 'baris yang menghalangi ditampilkan');
        $this->assertSame(4_000_000, $this->zahra->baseSalaryOn(Carbon::parse('2026-09-20')), 'tidak ada yang berubah');

        // --timpa: baris masa depan dihapus, gaji baru berlaku terus.
        $this->artisan('gaji:atur --divisi=barista --jumlah=3000000 --dari=2026-08-21 --timpa --ya')->assertSuccessful();
        $this->assertSame(3_000_000, $this->sigit->baseSalaryOn(Carbon::parse('2026-10-15')));
        $this->assertSame(1, $this->sigit->salaries()->whereNull('effective_to')->count());
    }

    /** Baris Rp 0 dari pendaftaran (employee:add tanpa gaji) bukan riwayat: dibersihkan, bukan ditolak. */
    public function test_placeholder_nol_di_masa_depan_dibersihkan(): void
    {
        $this->dea->salaries()->create([
            'salary_component_id' => SalaryComponent::where('code', 'gaji_pokok')->value('id'),
            'amount' => 0,
            'effective_from' => '2026-08-22',
        ]);

        $this->artisan('gaji:atur --pin=20 --jumlah=1500000 --dari=2026-08-21 --ya')->assertSuccessful();

        $this->assertSame(1_500_000, $this->dea->baseSalaryOn(Carbon::parse('2026-09-20')));
        $this->assertSame(1, $this->dea->salaries()->count(), 'placeholder terhapus');
    }

    public function test_divisi_tidak_dikenal_berhenti_dengan_pilihan(): void
    {
        $this->assertSame(1, Artisan::call('gaji:atur --divisi=kitchen --jumlah=3000000 --ya'));

        $this->assertStringContainsString('chef', Artisan::output());
    }
}
