<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Division;
use App\Models\Employee;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Roster\LiburPilihanService;
use App\Services\Roster\RosterService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * Libur pilihan sendiri dengan jatah per bulan (Logistik).
 *
 * Berlaku langsung tanpa persetujuan manajer, jadi jatahnya HARUS ditegakkan
 * di service — bukan di tampilan. Tombol yang disembunyikan tidak menghentikan
 * siapa pun yang mengirim form-nya langsung, dan di sini yang dipertaruhkan
 * adalah jadwal kafe.
 */
class LiburPilihanTest extends TestCase
{
    use RefreshDatabase;

    protected LiburPilihanService $service;

    protected RosterService $roster;

    protected Employee $indra;

    protected Shift $pagi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00:00', 'Asia/Jakarta'));

        $this->service = app(LiburPilihanService::class);
        $this->roster = app(RosterService::class);
        $this->pagi = Shift::where('code', 'pagi')->firstOrFail();

        $logistik = Division::firstOrCreate(['code' => 'logistik'], ['name' => 'Logistik']);

        $this->indra = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Indra Tri Setyo',
            'pin_device' => '23',
        ]);

        $this->indra->divisions()->attach($logistik->id, ['is_primary' => true]);
        $this->indra->load('divisions');

        // Masuk tiap hari sepanjang September.
        for ($t = Carbon::parse('2026-09-01', 'Asia/Jakarta'); $t->month === 9; $t->addDay()) {
            $this->roster->assign($this->roster->findOrCreate(2026, 9), $this->indra, $t->copy(), $this->pagi->id);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function tgl(string $t): Carbon
    {
        return Carbon::parse($t, 'Asia/Jakarta');
    }

    public function test_memilih_libur_mengubah_roster_hari_itu(): void
    {
        $this->service->pilih($this->indra, $this->tgl('2026-09-10'));

        $baris = RosterAssignment::where('employee_id', $this->indra->id)
            ->whereDate('work_date', $this->tgl('2026-09-10'))->sole();

        $this->assertNull($baris->shift_id);
        $this->assertSame('pilihan', $baris->source);
    }

    public function test_sisa_jatah_berkurang_setiap_dipilih(): void
    {
        $this->assertSame(2, $this->service->sisa($this->indra, $this->tgl('2026-09-01')));

        $this->service->pilih($this->indra, $this->tgl('2026-09-10'));
        $this->assertSame(1, $this->service->sisa($this->indra, $this->tgl('2026-09-01')));

        $this->service->pilih($this->indra, $this->tgl('2026-09-20'));
        $this->assertSame(0, $this->service->sisa($this->indra, $this->tgl('2026-09-01')));
    }

    /** Yang ketiga ditolak — jatahnya dijaga, bukan cuma diperingatkan. */
    public function test_pilihan_ketiga_ditolak(): void
    {
        $this->service->pilih($this->indra, $this->tgl('2026-09-10'));
        $this->service->pilih($this->indra, $this->tgl('2026-09-20'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sudah habis');

        $this->service->pilih($this->indra, $this->tgl('2026-09-25'));
    }

    /** Bulan berikutnya jatahnya penuh lagi. */
    public function test_jatah_dihitung_per_bulan_kalender(): void
    {
        $this->service->pilih($this->indra, $this->tgl('2026-09-10'));
        $this->service->pilih($this->indra, $this->tgl('2026-09-20'));

        $this->assertSame(2, $this->service->sisa($this->indra, $this->tgl('2026-10-01')));
    }

    /**
     * Hari terakhir bulan harus ikut terhitung. Kolom work_date tersimpan
     * sebagai "Y-m-d 00:00:00", jadi batas atas dengan tanggal pendek
     * membuangnya — jatahnya terlihat masih sisa padahal sudah habis.
     */
    public function test_libur_di_hari_terakhir_bulan_ikut_memotong_jatah(): void
    {
        $this->service->pilih($this->indra, $this->tgl('2026-09-30'));

        $this->assertSame(1, $this->service->sisa($this->indra, $this->tgl('2026-09-01')));
    }

    /** Libur yang dipasang admin bukan pilihan orangnya, jadi tidak memotong. */
    public function test_libur_dari_admin_tidak_memotong_jatah(): void
    {
        $this->roster->assign(
            $this->roster->findOrCreate(2026, 9), $this->indra, $this->tgl('2026-09-05'), null,
        );

        $this->assertSame(2, $this->service->sisa($this->indra, $this->tgl('2026-09-01')));
    }

    public function test_divisi_lain_tidak_boleh_memakai_jalur_ini(): void
    {
        $lain = Employee::factory()->create(['branch_id' => Branch::current()->id, 'name' => 'Bukan Logistik']);
        $lain->divisions()->attach(Division::where('code', 'barista')->firstOrFail()->id, ['is_primary' => true]);
        $lain->load('divisions');

        $this->roster->assign($this->roster->findOrCreate(2026, 9), $lain, $this->tgl('2026-09-10'), $this->pagi->id);

        $this->assertFalse($this->service->berlakuUntuk($lain));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak berlaku untuk posisi Anda');

        $this->service->pilih($lain, $this->tgl('2026-09-10'));
    }

    public function test_tanggal_yang_sudah_lewat_ditolak(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 08:00:00', 'Asia/Jakarta'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sudah lewat');

        $this->service->pilih($this->indra, $this->tgl('2026-09-10'));
    }

    public function test_tanggal_yang_memang_sudah_libur_ditolak(): void
    {
        $this->service->pilih($this->indra, $this->tgl('2026-09-10'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('memang sudah libur');

        $this->service->pilih($this->indra, $this->tgl('2026-09-10'));
    }

    public function test_tanggal_tanpa_jadwal_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Belum ada jadwal');

        $this->service->pilih($this->indra, $this->tgl('2026-10-05'));
    }

    // ------------------------------------------------------------ halaman

    protected function akun(Employee $employee): User
    {
        return User::factory()->create([
            'role' => UserRole::Karyawan,
            'employee_id' => $employee->id,
            'is_active' => true,
        ]);
    }

    /**
     * Langkah pertama TIDAK boleh menyimpan apa pun.
     *
     * Pilihannya berlaku langsung dan tidak bisa dibatalkan sendiri, jadi satu
     * salah klik berarti satu hari libur hilang. Layar konfirmasi ada supaya
     * yang disetujui adalah sesuatu yang sudah terbaca — kalau langkah pertama
     * diam-diam sudah menyimpan, layar itu cuma hiasan.
     */
    public function test_langkah_pertama_belum_menyimpan_apa_pun(): void
    {
        $halaman = $this->actingAs($this->akun($this->indra))
            ->post('/karyawan/libur', ['tanggal' => '2026-09-10']);

        $halaman->assertOk();
        $halaman->assertSee('Yakin mau libur tanggal ini?');

        $this->assertSame(2, $this->service->sisa($this->indra, $this->tgl('2026-09-01')));
    }

    public function test_konfirmasi_menyimpan_dan_memotong_jatah(): void
    {
        $this->actingAs($this->akun($this->indra))
            ->post('/karyawan/libur', ['tanggal' => '2026-09-10', 'konfirmasi' => '1'])
            ->assertRedirect(route('karyawan.libur.index'));

        $this->assertSame(1, $this->service->sisa($this->indra, $this->tgl('2026-09-01')));
    }

    /**
     * Menu yang disembunyikan bukan penjagaan. Yang mengirim form-nya langsung
     * harus tetap ditolak.
     */
    public function test_divisi_lain_ditolak_di_halaman_dan_saat_mengirim(): void
    {
        $lain = Employee::factory()->create(['branch_id' => Branch::current()->id, 'name' => 'Bukan Logistik']);
        $lain->divisions()->attach(Division::where('code', 'barista')->firstOrFail()->id, ['is_primary' => true]);

        $this->roster->assign($this->roster->findOrCreate(2026, 9), $lain, $this->tgl('2026-09-10'), $this->pagi->id);

        $akun = $this->akun($lain);

        $this->actingAs($akun)->get('/karyawan/libur')->assertForbidden();

        $this->actingAs($akun)
            ->post('/karyawan/libur', ['tanggal' => '2026-09-10', 'konfirmasi' => '1'])
            ->assertForbidden();

        $this->assertSame($this->pagi->id, RosterAssignment::where('employee_id', $lain->id)
            ->whereDate('work_date', $this->tgl('2026-09-10'))->sole()->shift_id);
    }

    /** Jatah habis: kirim langsung pun tetap ditolak dengan pesan, bukan 500. */
    public function test_jatah_habis_ditolak_saat_dikirim_langsung(): void
    {
        $this->service->pilih($this->indra, $this->tgl('2026-09-10'));
        $this->service->pilih($this->indra, $this->tgl('2026-09-20'));

        $this->actingAs($this->akun($this->indra))
            ->post('/karyawan/libur', ['tanggal' => '2026-09-25', 'konfirmasi' => '1'])
            ->assertSessionHasErrors('tanggal');

        $this->assertSame($this->pagi->id, RosterAssignment::where('employee_id', $this->indra->id)
            ->whereDate('work_date', $this->tgl('2026-09-25'))->sole()->shift_id);
    }

    /** Kandidat cuma hari kerja, dan yang sudah jadi libur hilang dari daftar. */
    public function test_kandidat_hanya_hari_kerja_mendatang(): void
    {
        $this->service->pilih($this->indra, $this->tgl('2026-09-10'));

        $kandidat = $this->service->kandidat($this->indra)->pluck('work_date')
            ->map(fn ($t) => $t->toDateString());

        $this->assertNotContains('2026-09-10', $kandidat);
        $this->assertContains('2026-09-11', $kandidat);
    }
}
