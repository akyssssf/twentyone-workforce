<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\Shift;
use App\Models\User;
use App\Services\Roster\RosterService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `lembur:tugaskan` — menugaskan lembur dari terminal.
 *
 * Jamnya tidak pernah diketik: lembur di kafe ini selalu menyambung shift
 * orangnya, dari jam pulang terjadwal sampai kafe tutup. Yang diputuskan
 * manusia cuma siapa, kapan, dan untuk keperluan apa — sisanya diturunkan dari
 * roster, supaya tidak ada angka yang berasal dari ingatan.
 */
class TugaskanLemburTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $dea;

    protected Carbon $tanggal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-09 12:00:00', 'Asia/Jakarta'));

        Shift::where('code', 'pagi')->update(['start_time' => '08:00:00', 'end_time' => '18:00:00']);
        Shift::where('code', 'malam')->update(['start_time' => '14:00:00', 'end_time' => '01:00:00']);

        User::factory()->create(['role' => UserRole::Admin]);

        $this->tanggal = Carbon::parse('2026-09-06', 'Asia/Jakarta');
        $pagi = Shift::where('code', 'pagi')->firstOrFail();

        $this->dea = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Dea Uji',
            'pin_device' => '20',
            'default_shift_id' => $pagi->id,
        ]);

        $service = app(RosterService::class);
        $service->assign($service->findOrCreate(2026, 9), $this->dea, $this->tanggal, $pagi->id);

        // Datang pagi, lalu scan lagi jam 01:00 dini hari — pola lembur yang
        // menyambung shift sampai kafe tutup.
        foreach (['2026-09-06 07:52:16', '2026-09-07 01:00:33'] as $waktu) {
            $at = Carbon::parse($waktu, 'Asia/Jakarta');

            AttendanceLog::create([
                'cloud_id' => 'UJI',
                'employee_id' => $this->dea->id,
                'pin' => '20',
                'scanned_at' => $at,
                'scan_minute' => $at->copy()->startOfMinute(),
                'source' => 'webhook',
            ]);
        }

        Artisan::call('attendance:compute', ['--from' => '2026-09-06', '--to' => '2026-09-06']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function rekap(): ?Attendance
    {
        return Attendance::where('employee_id', $this->dea->id)
            ->whereDate('work_date', $this->tanggal)
            ->first();
    }

    /** Jamnya diturunkan dari roster: pagi selesai 18:00, kafe tutup 01:00. */
    public function test_jam_lembur_diturunkan_dari_shift_bukan_diketik(): void
    {
        $this->artisan('lembur:tugaskan 20 2026-09-06 --keperluan=acara --alasan="Acara kafe, disetujui pemilik"')
            ->assertSuccessful();

        $lembur = OvertimeRequest::sole();

        $this->assertSame('18:00:00', $lembur->planned_start);
        $this->assertSame('01:00:00', $lembur->planned_end);
        $this->assertSame(420, (int) $lembur->planned_minutes);
    }

    /**
     * Penugasan saja TIDAK menghasilkan lembur terbayar. Durasinya baru
     * dihitung setelah karyawannya mengaktifkan kode — itu satu-satunya bukti
     * bahwa orang yang ditunjuk benar-benar mengerjakannya.
     */
    public function test_penugasan_saja_belum_terhitung_sebagai_lembur(): void
    {
        $this->artisan('lembur:tugaskan 20 2026-09-06 --keperluan=acara --alasan="Acara kafe"')
            ->assertSuccessful()
            ->expectsOutputToContain('BELUM dihitung');

        $this->assertSame(0, (int) $this->rekap()?->overtime_minutes);
    }

    /**
     * Kode aktivasi cuma berlaku ±1 hari dari tanggal lemburnya, jadi untuk
     * lembur yang sudah telanjur lewat jalur itu tertutup. --aktifkan adalah
     * pernyataan manajer, dan tercatat atas namanya.
     */
    public function test_aktifkan_membuat_lembur_terhitung(): void
    {
        $this->assertSame(0, (int) $this->rekap()?->overtime_minutes);

        $this->artisan('lembur:tugaskan 20 2026-09-06 --keperluan=acara --alasan="Acara kafe" --aktifkan')
            ->assertSuccessful();

        // 18:00 sampai kafe tutup 01:00 = 7 jam.
        $this->assertSame(420, (int) $this->rekap()?->overtime_minutes);
    }

    /**
     * "Pengganti" berarti ada posisi orang lain yang ditutup. Tanpa menyebut
     * siapa, catatannya tidak bisa menjelaskan apa pun nanti.
     */
    public function test_keperluan_pengganti_wajib_menyebut_yang_digantikan(): void
    {
        $this->artisan('lembur:tugaskan 20 2026-09-06 --keperluan=pengganti --alasan="Menggantikan rekan"')
            ->assertFailed();

        $this->assertSame(0, OvertimeRequest::count());
    }

    public function test_tanpa_alasan_ditolak(): void
    {
        $this->artisan('lembur:tugaskan 20 2026-09-06 --keperluan=acara')->assertFailed();

        $this->assertSame(0, OvertimeRequest::count());
    }

    public function test_keperluan_ngawur_ditolak(): void
    {
        $this->artisan('lembur:tugaskan 20 2026-09-06 --keperluan=nonton --alasan="apa saja"')->assertFailed();
    }

    /**
     * Shift yang berakhir setelah tengah malam tidak bisa disambung lembur —
     * setelah itu kafe sudah tutup.
     */
    public function test_shift_malam_tidak_bisa_ditugaskan_lembur(): void
    {
        $malam = Shift::where('code', 'malam')->firstOrFail();
        $service = app(RosterService::class);
        $service->assign($service->findOrCreate(2026, 9), $this->dea, $this->tanggal, $malam->id);

        $this->artisan('lembur:tugaskan 20 2026-09-06 --keperluan=acara --alasan="Acara kafe"')
            ->assertFailed();
    }

    public function test_tanpa_jadwal_shift_ditolak(): void
    {
        $lain = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Tanpa Jadwal',
            'pin_device' => '77',
            'default_shift_id' => Shift::where('code', 'pagi')->firstOrFail()->id,
        ]);

        $this->artisan("lembur:tugaskan {$lain->pin_device} 2026-09-06 --keperluan=acara --alasan=\"Acara\"")
            ->assertFailed();
    }

    public function test_pin_tidak_dikenal_ditolak(): void
    {
        $this->artisan('lembur:tugaskan 999 2026-09-06 --keperluan=acara --alasan="Acara"')->assertFailed();
    }
}
