<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\AttendanceLog;
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
 * Satu scan cuma boleh diklaim SATU baris jadwal.
 *
 * Kejadian nyata: seseorang terjadwal Pagi (08:00–18:00) dan Malam
 * (14:00–01:00) di hari yang sama, lalu menempel jari SEKALI jam 14:06.
 * Jendela kedua shift itu tumpang tindih, dan tiap baris mencari scannya
 * sendiri-sendiri — jadi scan yang sama terbaca sebagai jam masuk untuk
 * KEDUANYA: telat 7 menit di shift yang benar, dan telat 6 jam 7 menit di
 * shift pagi yang tidak pernah dia jalani. Tidak ada error apa pun; yang ada
 * cuma potongan gaji atas hari yang tidak pernah terjadi.
 *
 * Perbaikan yang paling gampang — "berikan scan ke shift yang jam mulainya
 * paling dekat" — justru merusak dobel shift yang asli: scan PULANG shift
 * pertama akan lari ke shift kedua. Karena itu kepemilikan diukur ke seluruh
 * rentang jadwal, bukan ke jam masuknya saja, dan kedua kasus itu dijaga di
 * sini bersamaan.
 */
class SatuScanSatuShiftTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $karyawan;

    protected Carbon $tanggal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);

        // Dipatok setelah semua jendela tanggal uji tutup.
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00:00', 'Asia/Jakarta'));

        $this->tanggal = Carbon::parse('2026-08-29', 'Asia/Jakarta');

        // Jam kafe yang sebenarnya, disetel eksplisit karena seeder masih
        // membawa jam lama (Pagi 09:00–17:00, Malam 17:00–01:00). Tumpang
        // tindihnya justru inti kasus ini: Pagi berakhir 18:00 sementara Malam
        // sudah mulai 14:00, jadi jam 14:06 berada di dalam rentang KEDUANYA.
        Shift::where('code', 'pagi')->update(['start_time' => '08:00:00', 'end_time' => '18:00:00']);
        Shift::where('code', 'malam')->update(['start_time' => '14:00:00', 'end_time' => '01:00:00']);

        $this->karyawan = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Uji Dobel',
            'pin_device' => '81',
            'default_shift_id' => Shift::where('code', 'pagi')->firstOrFail()->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Baris kedua dibuat langsung, bukan lewat assign(): memanggil assign() dua
     * kali justru MEMINDAHKAN shift, bukan menambah baris kedua.
     */
    protected function jadwalkanDobel(Shift $pertama, Shift $kedua): void
    {
        $roster = app(RosterService::class);
        $baris = $roster->assign(
            $roster->findOrCreate(2026, 8),
            $this->karyawan,
            $this->tanggal,
            $pertama->id,
        );

        RosterAssignment::create([
            'roster_id' => $baris->roster_id,
            'employee_id' => $this->karyawan->id,
            'work_date' => $this->tanggal->copy()->startOfDay(),
            'shift_id' => $kedua->id,
            'status' => AssignmentStatus::Scheduled,
            'source' => 'manual',
        ]);
    }

    protected function scan(string $waktu): void
    {
        $at = Carbon::parse($waktu, 'Asia/Jakarta');

        AttendanceLog::create([
            'cloud_id' => 'UJI',
            'employee_id' => $this->karyawan->id,
            'pin' => $this->karyawan->pin_device,
            'scanned_at' => $at,
            'scan_minute' => $at->copy()->startOfMinute(),
            'source' => 'webhook',
        ]);
    }

    protected function rekap(Shift $shift): ?Attendance
    {
        Artisan::call('attendance:compute', [
            '--from' => $this->tanggal->toDateString(),
            '--to' => $this->tanggal->toDateString(),
        ]);

        return Attendance::where('employee_id', $this->karyawan->id)
            ->whereDate('work_date', $this->tanggal)
            ->where('shift_key', $shift->id)
            ->first();
    }

    /** Kasus Fikri: dua shift tumpang tindih, satu scan. */
    public function test_satu_scan_tidak_dihitung_dua_kali_di_shift_yang_tumpang_tindih(): void
    {
        $pagi = Shift::where('code', 'pagi')->firstOrFail();
        $malam = Shift::where('code', 'malam')->firstOrFail();

        $this->jadwalkanDobel($pagi, $malam);
        $this->scan('2026-08-29 14:06:00');

        // Scan-nya milik Malam: jam masuknya 14:00, jadi telat 6 menit saja.
        $this->assertSame('14:06', $this->rekap($malam)?->check_in_at?->format('H:i'));
        $this->assertSame(6, $this->rekap($malam)?->late_minutes);

        // Pagi tidak dapat scan sama sekali. Yang penting: TIDAK ADA telat
        // berjam-jam palsu — dia memang tidak pernah menjalani shift itu.
        $this->assertNull($this->rekap($pagi)?->check_in_at);
        $this->assertSame(0, $this->rekap($pagi)?->late_minutes);
    }

    /** Baris yang kalah jatuh ke alpha — salah jadwalnya jadi terlihat. */
    public function test_shift_yang_tidak_dijalani_jadi_alpha_bukan_telat_berjam_jam(): void
    {
        $pagi = Shift::where('code', 'pagi')->firstOrFail();
        $malam = Shift::where('code', 'malam')->firstOrFail();

        $this->jadwalkanDobel($pagi, $malam);
        $this->scan('2026-08-29 14:06:00');

        $this->assertSame(AttendanceStatus::Alpha, $this->rekap($pagi)?->status);
        $this->assertSame(AttendanceStatus::Hadir, $this->rekap($malam)?->status);
    }

    /**
     * Dobel shift yang ASLI (jamnya tidak tumpang tindih) tidak boleh rusak.
     * Jendelanya tetap beririsan karena toleransi 4 jam, jadi ini justru kasus
     * yang membuktikan pembagiannya diukur ke rentang jadwal, bukan jam masuk.
     */
    public function test_dobel_shift_asli_tetap_dapat_jam_masuk_dan_pulangnya_sendiri(): void
    {
        $subuh = Shift::create([
            'name' => 'Shift Subuh Uji', 'code' => 'subuh-uji',
            'start_time' => '08:00:00', 'end_time' => '12:00:00',
            'crosses_midnight' => false, 'break_minutes' => 0, 'is_break_paid' => true,
            'window_before_hours' => 4, 'window_after_hours' => 4,
            'color' => '#000000', 'is_active' => true,
        ]);

        $sore = Shift::create([
            'name' => 'Shift Sore Uji', 'code' => 'sore-uji',
            'start_time' => '16:00:00', 'end_time' => '20:00:00',
            'crosses_midnight' => false, 'break_minutes' => 0, 'is_break_paid' => true,
            'window_before_hours' => 4, 'window_after_hours' => 4,
            'color' => '#000000', 'is_active' => true,
        ]);

        $this->jadwalkanDobel($subuh, $sore);

        $this->scan('2026-08-29 07:55:00');
        $this->scan('2026-08-29 12:05:00');
        $this->scan('2026-08-29 15:58:00');
        $this->scan('2026-08-29 20:10:00');

        // Scan 12:05 berada di dalam JENDELA kedua shift, tapi rentang jadwalnya
        // jelas milik Subuh. Kalau diukur dari jam masuk, dia akan lari ke Sore.
        $this->assertSame('07:55', $this->rekap($subuh)?->check_in_at?->format('H:i'));
        $this->assertSame('12:05', $this->rekap($subuh)?->check_out_at?->format('H:i'));

        $this->assertSame('15:58', $this->rekap($sore)?->check_in_at?->format('H:i'));
        $this->assertSame('20:10', $this->rekap($sore)?->check_out_at?->format('H:i'));

        $this->assertSame(0, $this->rekap($subuh)?->late_minutes);
        $this->assertSame(0, $this->rekap($sore)?->late_minutes);
    }

    /** Satu jadwal saja harus berperilaku persis seperti sebelumnya. */
    public function test_satu_jadwal_tidak_berubah_perilakunya(): void
    {
        $pagi = Shift::where('code', 'pagi')->firstOrFail();

        $roster = app(RosterService::class);
        $roster->assign($roster->findOrCreate(2026, 8), $this->karyawan, $this->tanggal, $pagi->id);

        $this->scan('2026-08-29 08:05:00');
        $this->scan('2026-08-29 18:02:00');

        $this->assertSame('08:05', $this->rekap($pagi)?->check_in_at?->format('H:i'));
        $this->assertSame('18:02', $this->rekap($pagi)?->check_out_at?->format('H:i'));
        $this->assertSame(5, $this->rekap($pagi)?->late_minutes);
    }
}
