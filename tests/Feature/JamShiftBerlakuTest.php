<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftTimeOverride;
use App\Services\Roster\RosterService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Jam shift yang berlaku per rentang tanggal.
 *
 * Inti yang dijaga: "mulai 21 September Shift 2 tutup 23:30" TIDAK boleh
 * menyentuh 20 September ke belakang. Jam master berlaku global, jadi
 * mengubahnya ikut menggeser pulang cepat dan lembur di bulan yang belum
 * digaji begitu dihitung ulang — tanpa error, tanpa tanda.
 */
class JamShiftBerlakuTest extends TestCase
{
    use RefreshDatabase;

    protected Shift $malam;

    protected Employee $karyawan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-25 12:00:00', 'Asia/Jakarta'));

        Shift::where('code', 'malam')->update(['start_time' => '14:00:00', 'end_time' => '01:00:00']);
        $this->malam = Shift::where('code', 'malam')->firstOrFail();

        $this->karyawan = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Uji Jam',
            'pin_device' => '61',
            'default_shift_id' => $this->malam->id,
        ]);

        $service = app(RosterService::class);

        // Dua hari yang mengapit perubahan. Scan pulang dipilih yang masuk
        // jendela tangkap (≤60 menit sebelum jam pulang terjadwal) — pulang
        // lebih awal dari itu tidak pernah tertangkap sebagai jam pulang.
        $skenario = [
            '2026-09-20' => ['2026-09-20 14:02:00', '2026-09-21 00:30:00'], // jadwal lama 01:00 → cepat 30m
            '2026-09-21' => ['2026-09-21 14:02:00', '2026-09-21 23:35:00'], // jadwal baru 23:30 → tidak cepat
        ];

        foreach ($skenario as $tanggal => $scans) {
            $t = Carbon::parse($tanggal, 'Asia/Jakarta');
            $service->assign($service->findOrCreate(2026, 9), $this->karyawan, $t, $this->malam->id);

            foreach ($scans as $waktu) {
                $at = Carbon::parse($waktu, 'Asia/Jakarta');

                AttendanceLog::create([
                    'cloud_id' => 'UJI', 'employee_id' => $this->karyawan->id, 'pin' => '61',
                    'scanned_at' => $at, 'scan_minute' => $at->copy()->startOfMinute(), 'source' => 'webhook',
                ]);
            }
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function rekap(string $tanggal): ?Attendance
    {
        Artisan::call('attendance:compute', ['--from' => $tanggal, '--to' => $tanggal]);

        return Attendance::where('employee_id', $this->karyawan->id)
            ->whereDate('work_date', Carbon::parse($tanggal))
            ->first();
    }

    /** Ini intinya: 21 Sep pakai jam baru, 20 Sep tetap jam lama. */
    public function test_jam_baru_berlaku_mulai_tanggalnya_dan_tanggal_lama_tidak_tersentuh(): void
    {
        $this->artisan('shift:jam malam --dari=2026-09-21 --mulai=14:00 --selesai=23:30')
            ->assertSuccessful();

        // 20 Sep: pulang 00:30 dari jadwal 01:00 = pulang cepat 30 menit, seperti dulu.
        $this->assertSame('01:00', $this->rekap('2026-09-20')?->scheduled_out?->format('H:i'));
        $this->assertSame(30, (int) $this->rekap('2026-09-20')?->early_leave_minutes);

        // 21 Sep: pulang 23:35 dari jadwal 23:30 = tidak pulang cepat, dan
        // jam pulangnya tertangkap karena sudah di dalam jendela tangkap baru.
        $this->assertSame('23:30', $this->rekap('2026-09-21')?->scheduled_out?->format('H:i'));
        $this->assertSame('23:35', $this->rekap('2026-09-21')?->check_out_at?->format('H:i'));
        $this->assertSame(0, (int) $this->rekap('2026-09-21')?->early_leave_minutes);
    }

    public function test_jam_master_tidak_disentuh(): void
    {
        $this->artisan('shift:jam malam --dari=2026-09-21 --mulai=14:00 --selesai=23:30');

        $this->assertSame('01:00:00', $this->malam->fresh()->end_time);
    }

    /** Perubahan berikutnya menutup periode sebelumnya sehari sebelum mulainya. */
    public function test_periode_lama_ditutup_otomatis(): void
    {
        $this->artisan('shift:jam malam --dari=2026-09-21 --mulai=14:00 --selesai=23:30');
        $this->artisan('shift:jam malam --dari=2026-10-01 --mulai=14:00 --selesai=23:00')->assertSuccessful();

        $pertama = ShiftTimeOverride::where('shift_id', $this->malam->id)->orderBy('effective_from')->first();

        $this->assertSame('2026-09-30', $pertama->effective_to->toDateString());
        $this->assertSame('23:30:00', $this->malam->fresh()->jamPada(Carbon::parse('2026-09-25'))['end_time']);
        $this->assertSame('23:00:00', $this->malam->fresh()->jamPada(Carbon::parse('2026-10-05'))['end_time']);
    }

    /** Tumpang tindih yang bukan "menutup periode terbuka" ditolak, bukan ditebak. */
    public function test_tumpang_tindih_sungguhan_ditolak(): void
    {
        $this->artisan('shift:jam malam --dari=2026-09-21 --sampai=2026-09-30 --mulai=14:00 --selesai=23:30');
        $this->artisan('shift:jam malam --dari=2026-09-25 --mulai=14:00 --selesai=22:00')->assertFailed();

        $this->assertSame(1, ShiftTimeOverride::count());
    }

    /** Lembur ikut memakai jam yang berlaku: batas tutup kafe bergeser. */
    public function test_batas_tutup_kafe_ikut_jam_yang_berlaku(): void
    {
        // Middle juga tutup 01:00; supaya batas harinya benar-benar ditentukan
        // shift malam, middle ikut dipindah.
        $this->artisan('shift:jam malam --dari=2026-09-21 --mulai=14:00 --selesai=23:30');
        $this->artisan('shift:jam middle --dari=2026-09-21 --mulai=12:00 --selesai=23:30');

        $this->assertSame('2026-09-21 01:00', $this->malam->fresh()->endsOn(Carbon::parse('2026-09-20', 'Asia/Jakarta'))->format('Y-m-d H:i'));
        $this->assertSame('2026-09-21 23:30', $this->malam->fresh()->endsOn(Carbon::parse('2026-09-21', 'Asia/Jakarta'))->format('Y-m-d H:i'));
    }

    public function test_hapus_mengembalikan_ke_jam_master(): void
    {
        $this->artisan('shift:jam malam --dari=2026-09-21 --mulai=14:00 --selesai=23:30');
        $this->artisan('shift:jam malam --dari=2026-09-21 --hapus')->assertSuccessful();

        $this->assertSame('01:00', $this->rekap('2026-09-21')?->scheduled_out?->format('H:i'));
    }

    public function test_tanpa_dari_ditolak(): void
    {
        $this->artisan('shift:jam malam --mulai=14:00 --selesai=23:30')->assertFailed();
    }

    public function test_jam_ngawur_ditolak(): void
    {
        $this->artisan('shift:jam malam --dari=2026-09-21 --mulai=14:00 --selesai=25:00')->assertFailed();
        $this->assertSame(0, ShiftTimeOverride::count());
    }
}
