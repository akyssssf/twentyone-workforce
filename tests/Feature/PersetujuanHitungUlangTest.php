<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Services\Requests\RequestService;
use App\Services\Roster\RosterService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Persetujuan pengajuan harus langsung terlihat di REKAP, bukan cuma di roster.
 *
 * Celah yang ditutup: cron hanya menghitung ulang DUA HARI TERAKHIR, dan
 * approve() tidak pernah memicu hitung ulang sama sekali. Akibatnya persetujuan
 * untuk tanggal yang lebih lama dari itu mengubah roster dan menulis koreksi,
 * tapi rekapnya tetap memperlihatkan angka lama — di halaman Roster perubahannya
 * terlihat, di Rekap Absensi tidak. Tidak ada error, tidak ada tanda apa pun,
 * dan yang menyetujui wajar menyimpulkan pengajuannya tidak berfungsi.
 *
 * Semua jalur admin lain (roster:set, attendance:tandai, roster:jam-khusus)
 * sudah menghitung ulang. Justru jalur yang dipakai karyawan yang tidak.
 */
class PersetujuanHitungUlangTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $karyawan;

    protected Employee $saksi;

    protected User $manajer;

    protected RequestService $service;

    protected Carbon $tanggalLama;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-30 12:00:00', 'Asia/Jakarta'));

        $this->service = app(RequestService::class);
        $this->manajer = User::factory()->create(['role' => UserRole::Admin]);

        $pagi = Shift::where('code', 'pagi')->firstOrFail();

        $this->karyawan = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Yang Lupa Scan',
            'pin_device' => '61',
            'default_shift_id' => $pagi->id,
        ]);

        $this->saksi = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Saksi',
            'pin_device' => '62',
            'default_shift_id' => $pagi->id,
        ]);

        // Sepuluh hari lalu — JAUH di luar jendela cron yang cuma 2 hari.
        $this->tanggalLama = Carbon::parse('2026-08-20', 'Asia/Jakarta');

        $roster = app(RosterService::class);
        $roster->assign(
            $roster->findOrCreate(2026, 8),
            $this->karyawan,
            $this->tanggalLama,
            $pagi->id,
        );

        Artisan::call('attendance:compute', [
            '--from' => $this->tanggalLama->toDateString(),
            '--to' => $this->tanggalLama->toDateString(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function rekap(): ?Attendance
    {
        return Attendance::where('employee_id', $this->karyawan->id)
            ->whereDate('work_date', $this->tanggalLama)
            ->first();
    }

    /**
     * Inti perkaranya: setelah disetujui, rekap harus SUDAH berubah tanpa ada
     * yang menjalankan attendance:compute secara manual.
     */
    public function test_koreksi_yang_disetujui_langsung_terlihat_di_rekap(): void
    {
        // Tidak ada scan sama sekali, jadi berangkat dari alpha tanpa jam masuk.
        $this->assertNull($this->rekap()?->check_in_at);

        $request = $this->service->submitCorrection($this->karyawan, [
            'work_date' => $this->tanggalLama->toDateString(),
            'correction_type' => 'lupa_masuk',
            'proposed_check_in' => $this->tanggalLama->copy()->setTime(8, 5)->toDateTimeString(),
            'reason' => 'Mesin tidak membaca jari, ada saksi',
            'substitute_employee_id' => $this->saksi->id,
        ]);

        $this->service->peerRespond($request->fresh(), $this->saksi, true, 'Benar, saya lihat');
        $this->service->approve($request->fresh(), $this->manajer, 'Disetujui');

        // TANPA attendance:compute manual di antaranya.
        $this->assertSame('08:05', $this->rekap()?->check_in_at?->format('H:i'));
    }

    /** Hitung ulang tidak boleh menghapus jejak koreksinya. */
    public function test_koreksi_tetap_menempel_setelah_dihitung_ulang_lagi(): void
    {
        $request = $this->service->submitCorrection($this->karyawan, [
            'work_date' => $this->tanggalLama->toDateString(),
            'correction_type' => 'lupa_masuk',
            'proposed_check_in' => $this->tanggalLama->copy()->setTime(8, 5)->toDateTimeString(),
            'reason' => 'Mesin tidak membaca jari, ada saksi',
            'substitute_employee_id' => $this->saksi->id,
        ]);

        $this->service->peerRespond($request->fresh(), $this->saksi, true, 'Benar');
        $this->service->approve($request->fresh(), $this->manajer, 'Disetujui');

        Artisan::call('attendance:compute', [
            '--from' => $this->tanggalLama->toDateString(),
            '--to' => $this->tanggalLama->toDateString(),
        ]);

        $this->assertSame('08:05', $this->rekap()?->check_in_at?->format('H:i'));
        $this->assertTrue($this->rekap()->has_adjustment);
    }
}
