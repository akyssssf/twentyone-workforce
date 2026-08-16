<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Division;
use App\Models\Employee;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Requests\RequestService;
use App\Services\Roster\RosterService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * Tukar shift mutual (dua arah) menukar ISI dua baris roster (shift &
 * divisi), bukan kepemilikannya — lihat RequestService::applySwap(). Kalau
 * yang ditukar kepemilikannya, dua baris yang KEBETULAN shift_key-nya sama
 * (dua-duanya sama-sama Shift Pagi, cuma beda posisi) akan sempat
 * bertabrakan di tengah proses, karena SQLite tidak menunda pengecekan
 * constraint unik sampai akhir statement.
 *
 * Pengambilalihan satu arah (rekan mengambil shift tanpa memberi balik) tetap
 * lewat pemindahan kepemilikan seperti biasa, karena memang tidak ada baris
 * kedua untuk saling ditukar isinya — dan di situ tetap harus dijaga supaya
 * tidak membuat penerima dobel-pemilik shift yang identik, yang mustahil
 * secara fisik. Ini pernah lolos sampai produksi sebagai error mentah dari
 * database, bukan pesan yang bisa dipahami manajer.
 */
class ShiftSwapTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected RequestService $service;

    protected RosterService $roster;

    protected Shift $pagi;

    protected Shift $malam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-10 09:00:00', 'Asia/Jakarta'));

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->service = app(RequestService::class);
        $this->roster = app(RosterService::class);

        $this->pagi = Shift::where('code', 'pagi')->first();
        $this->malam = Shift::where('code', 'malam')->first();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function karyawan(string $nama): Employee
    {
        return Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => $nama,
        ]);
    }

    protected function jadwalkan(Employee $employee, Shift $shift, Carbon $tanggal): RosterAssignment
    {
        $roster = $this->roster->findOrCreate($tanggal->year, $tanggal->month);

        return $this->roster->assign($roster, $employee, $tanggal, $shift->id);
    }

    /** Ajukan lalu langsung setujui, jalur pengganti-terima-otomatis manajer. */
    protected function tukarDanSetujui(Employee $pengaju, RosterAssignment $jadwalPengaju, Employee $rekan, ?RosterAssignment $jadwalRekan = null): void
    {
        $this->actingAs($this->admin);

        $request = $this->service->submitSwap($pengaju, [
            'requester_assignment_id' => $jadwalPengaju->id,
            'partner_employee_id' => $rekan->id,
            'partner_assignment_id' => $jadwalRekan?->id,
            'reason' => 'Ada keperluan',
        ]);

        $this->service->peerRespond($request->fresh(), $rekan, true, 'Bersedia');
        $this->service->approve($request->fresh(), $this->admin, 'Disetujui');
    }

    public function test_tukar_posisi_dua_orang_yang_sama_sama_pagi_tetap_jalan(): void
    {
        $tanggal = Carbon::parse('2026-08-15', 'Asia/Jakarta');
        $barista = Division::create(['code' => 'barista-uji', 'name' => 'Barista Uji']);
        $kasir = Division::create(['code' => 'kasir-uji', 'name' => 'Kasir Uji']);

        $a = $this->karyawan('A Barista Pagi');
        $b = $this->karyawan('B Kasir Pagi');

        // Sama-sama Pagi, cuma beda posisi — bukan kasus yang mustahil, karena
        // yang tertukar ISI baris (divisinya), kepemilikannya (employee_id)
        // tidak pernah disentuh sama sekali.
        $jadwalA = $this->roster->assign($this->roster->findOrCreate(2026, 8), $a, $tanggal, $this->pagi->id, $barista->id);
        $jadwalB = $this->roster->assign($this->roster->findOrCreate(2026, 8), $b, $tanggal, $this->pagi->id, $kasir->id);

        $this->tukarDanSetujui($a, $jadwalA, $b, $jadwalB);

        // Kepemilikan baris tetap: A masih pemilik jadwalA, B masih jadwalB.
        $this->assertSame($a->id, $jadwalA->fresh()->employee_id);
        $this->assertSame($b->id, $jadwalB->fresh()->employee_id);

        // Yang berubah cuma isinya: A sekarang di posisi Kasir, B di Barista.
        $this->assertSame($kasir->id, $jadwalA->fresh()->division_id);
        $this->assertSame($barista->id, $jadwalB->fresh()->division_id);

        // Tidak ada yang dobel: masing-masing tetap cuma satu baris hari itu.
        $this->assertSame(1, RosterAssignment::where('employee_id', $a->id)->whereDate('work_date', $tanggal)->count());
        $this->assertSame(1, RosterAssignment::where('employee_id', $b->id)->whereDate('work_date', $tanggal)->count());
    }

    /**
     * Ini persis kejadian yang sempat lolos ke produksi: Fikri (Pagi) "ngasih"
     * shiftnya ke Faza yang KEBETULAN sudah Pagi juga hari itu, tanpa Faza
     * memberi apa pun balik. Faza akan berakhir dobel-pemilik shift Pagi yang
     * identik — mustahil secara fisik, harus ditolak dengan pesan yang jelas,
     * bukan error database mentah.
     */
    public function test_mengambil_alih_shift_yang_sudah_dipunya_penerima_ditolak(): void
    {
        $tanggal = Carbon::parse('2026-08-15', 'Asia/Jakarta');

        $fikri = $this->karyawan('Fikri');
        $faza = $this->karyawan('Faza');

        $jadwalFikri = $this->jadwalkan($fikri, $this->pagi, $tanggal);
        $this->jadwalkan($faza, $this->pagi, $tanggal);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dobel');

        // Rekan mengambil alih tanpa memberi balik (theirs === null).
        $this->tukarDanSetujui($fikri, $jadwalFikri, $faza);
    }

    /** Kalau penerima tidak ada jadwal sama sekali hari itu, pengambilalihan wajar-wajar saja. */
    public function test_mengambil_alih_shift_orang_yang_kosong_hari_itu_tetap_jalan(): void
    {
        $tanggal = Carbon::parse('2026-08-15', 'Asia/Jakarta');

        $fikri = $this->karyawan('Fikri');
        $faza = $this->karyawan('Faza Kosong');

        $jadwalFikri = $this->jadwalkan($fikri, $this->pagi, $tanggal);

        $this->tukarDanSetujui($fikri, $jadwalFikri, $faza);

        // Pengambilalihan (tanpa memberi balik) TETAP lewat pemindahan
        // kepemilikan — beda dari tukar-mutual di atas, karena tidak ada
        // baris kedua untuk saling ditukar isinya.
        $this->assertSame($faza->id, $jadwalFikri->fresh()->employee_id);
    }
}
