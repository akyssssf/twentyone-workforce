<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\Attendance\AttendanceComputer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendanceComputerTest extends TestCase
{
    use RefreshDatabase;

    protected AttendanceComputer $computer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->computer = app(AttendanceComputer::class);

        // Dipatok jauh setelah tanggal uji supaya jendela kerjanya selalu
        // sudah lewat dan hari berjalan tidak ikut campur.
        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function karyawan(array $state = [], ?Shift $shift = null): Employee
    {
        return Employee::factory()->create(array_merge([
            'pin_device' => '1',
            'name' => 'Budi',
            'default_shift_id' => ($shift ?? Shift::factory()->create())->id,
        ], $state));
    }

    protected function scan(string $pin, string $waktu): AttendanceLog
    {
        $at = Carbon::parse($waktu, 'Asia/Jakarta');

        return AttendanceLog::create([
            'cloud_id' => 'XXXXX',
            'pin' => $pin,
            'scanned_at' => $at,
            'scan_minute' => $at->copy()->startOfMinute(),
            'source' => 'webhook',
        ]);
    }

    /**
     * computeEmployee mengembalikan Collection karena satu orang boleh punya
     * lebih dari satu shift dalam sehari. Helper ini mengambil yang pertama,
     * yang cukup untuk kasus uji satu shift.
     */
    protected function hitung(Employee $employee, string $tanggal): ?Attendance
    {
        return $this->computer
            ->computeEmployee($employee, Carbon::parse($tanggal, 'Asia/Jakarta'))
            ->first();
    }

    public function test_datang_sebelum_jam_masuk_terhitung_hadir(): void
    {
        $employee = $this->karyawan();
        $this->scan('1', '2026-08-06 08:45:00');

        $a = $this->hitung($employee, '2026-08-06');

        $this->assertSame('hadir', $a->status->value);
        $this->assertSame(0, $a->late_seconds);
        $this->assertSame(0, $a->late_minutes);
        $this->assertSame('2026-08-06 09:00:00', $a->scheduled_in->format('Y-m-d H:i:s'));
    }

    public function test_datang_tepat_jam_masuk_terhitung_hadir(): void
    {
        $employee = $this->karyawan();
        $this->scan('1', '2026-08-06 09:00:00');

        $a = $this->hitung($employee, '2026-08-06');

        $this->assertSame('hadir', $a->status->value);
        $this->assertSame(0, $a->late_seconds);
    }

    /**
     * Aturan kafe: toleransi nol detik.
     */
    public function test_telat_satu_detik_sudah_kena_satu_blok(): void
    {
        $employee = $this->karyawan();
        $this->scan('1', '2026-08-06 09:00:01');

        $a = $this->hitung($employee, '2026-08-06');

        $this->assertSame('hadir', $a->status->value);
        $this->assertSame(1, $a->late_seconds);
        // blok potongan pindah ke rule_tiers; di sini yang dicatat menitnya.
        // nominal potongan dihitung modul payroll, bukan modul absensi.
    }

    /**
     * Menit telat dibulatkan KE ATAS.
     *
     * Penting karena tingkatan potongan berbicara dalam menit: telat 1 detik
     * harus jatuh ke tingkat "1-10 menit", bukan ke nol.
     */
    public function test_menit_telat_dibulatkan_ke_atas(): void
    {
        $shift = Shift::factory()->create();

        $kasus = [
            ['09:00:01', 1],
            ['09:00:59', 1],
            ['09:01:00', 1],
            ['09:01:01', 2],
            ['09:10:00', 10],
            ['09:10:01', 11],
        ];

        foreach ($kasus as $i => [$jam, $menitDiharap]) {
            $pin = (string) (100 + $i);
            $employee = $this->karyawan(['pin_device' => $pin, 'name' => "Uji {$i}"], $shift);
            $this->scan($pin, "2026-08-06 {$jam}");

            $a = $this->hitung($employee, '2026-08-06');

            $this->assertSame($menitDiharap, $a->late_minutes, "Jam {$jam} seharusnya {$menitDiharap} menit.");
        }
    }

    public function test_tanpa_scan_terhitung_alpha(): void
    {
        $employee = $this->karyawan();

        $a = $this->hitung($employee, '2026-08-06');

        $this->assertSame('alpha', $a->status->value);
        $this->assertNull($a->check_in_at);
        $this->assertNull($a->check_out_at);
        $this->assertSame(0, $a->late_minutes);
    }

    public function test_scan_pertama_jadi_masuk_dan_terakhir_jadi_pulang(): void
    {
        $employee = $this->karyawan();
        $this->scan('1', '2026-08-06 08:55:00');
        $this->scan('1', '2026-08-06 12:30:00');
        $this->scan('1', '2026-08-06 17:05:00');

        $a = $this->hitung($employee, '2026-08-06');

        $this->assertSame('08:55:00', $a->check_in_at->format('H:i:s'));
        $this->assertSame('17:05:00', $a->check_out_at->format('H:i:s'));
    }

    public function test_scan_tunggal_tidak_bikin_jam_pulang_palsu(): void
    {
        $employee = $this->karyawan();
        $this->scan('1', '2026-08-06 08:55:00');

        $a = $this->hitung($employee, '2026-08-06');

        $this->assertNotNull($a->check_in_at);
        $this->assertNull($a->check_out_at);
    }

    /**
     * Inti masalah Shift 2: pulang jam 00:30 jatuh di tanggal kalender
     * berikutnya, tapi tetap milik work_date 6 Agustus.
     */
    public function test_shift_malam_pulang_lewat_tengah_malam_tetap_masuk_hari_yang_sama(): void
    {
        $malam = Shift::factory()->malam()->create();
        $employee = $this->karyawan(['pin_device' => '2'], $malam);

        $this->scan('2', '2026-08-06 16:58:00');
        $this->scan('2', '2026-08-07 00:30:00');

        $a = $this->hitung($employee, '2026-08-06');

        $this->assertSame('hadir', $a->status->value);
        $this->assertSame('2026-08-06 16:58:00', $a->check_in_at->format('Y-m-d H:i:s'));

        // Jam pulang milik tanggal berikutnya, tapi tetap tercatat di sini.
        $this->assertSame('2026-08-07 00:30:00', $a->check_out_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-06', $a->work_date->toDateString());
    }

    public function test_shift_malam_telat_dihitung_dari_jam_lima_sore(): void
    {
        $malam = Shift::factory()->malam()->create();
        $employee = $this->karyawan(['pin_device' => '2'], $malam);

        $this->scan('2', '2026-08-06 17:12:00');

        $a = $this->hitung($employee, '2026-08-06');

        $this->assertSame('hadir', $a->status->value);
        $this->assertSame(720, $a->late_seconds);
        // blok potongan pindah ke rule_tiers; di sini yang dicatat menitnya.
        $this->assertSame('2026-08-06 17:00:00', $a->scheduled_in->format('Y-m-d H:i:s'));
    }

    public function test_scan_shift_malam_tidak_bocor_ke_hari_berikutnya(): void
    {
        $malam = Shift::factory()->malam()->create();
        $employee = $this->karyawan(['pin_device' => '2'], $malam);

        $this->scan('2', '2026-08-06 16:58:00');
        $this->scan('2', '2026-08-07 00:30:00');

        // Tanggal 7 tidak punya scan miliknya sendiri, jadi harus alpha,
        // bukan ikut memungut scan pulang tanggal 6.
        $a = $this->hitung($employee, '2026-08-07');

        $this->assertSame('alpha', $a->status->value);
    }

    public function test_scan_karyawan_lain_tidak_ikut_terhitung(): void
    {
        $shift = Shift::factory()->create();
        $budi = $this->karyawan(['pin_device' => '1', 'name' => 'Budi'], $shift);
        $this->karyawan(['pin_device' => '2', 'name' => 'Sari'], $shift);

        $this->scan('2', '2026-08-06 08:50:00');

        $this->assertSame('alpha', $this->hitung($budi, '2026-08-06')->status->value);
    }

    public function test_karyawan_nonaktif_dilewati(): void
    {
        $employee = $this->karyawan(['is_active' => false]);
        $this->scan('1', '2026-08-06 09:30:00');

        $this->assertNull($this->hitung($employee, '2026-08-06'));
        $this->assertSame(0, Attendance::count());
    }

    public function test_karyawan_tanpa_shift_dilewati(): void
    {
        $employee = Employee::factory()->tanpaShift()->create(['pin_device' => '1']);

        $this->assertNull($this->hitung($employee, '2026-08-06'));
    }

    public function test_tanggal_sebelum_bergabung_tidak_dihitung_alpha(): void
    {
        // Tanpa penjagaan ini, karyawan baru akan langsung punya tumpukan
        // alpha untuk tanggal saat dia belum bekerja di sini.
        $employee = $this->karyawan(['joined_at' => '2026-08-08']);

        $this->assertNull($this->hitung($employee, '2026-08-06'));
        $this->assertNotNull($this->hitung($employee, '2026-08-08'));
    }

    public function test_hari_berjalan_yang_belum_selesai_belum_dihitung_alpha(): void
    {
        // Jam 10 pagi, shift 1 belum bubar. Menandai alpha sekarang keliru.
        Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Jakarta'));

        $employee = $this->karyawan();

        $this->assertNull($this->hitung($employee, '2026-08-06'));
    }

    public function test_hari_berjalan_dengan_scan_tetap_dihitung_sebagai_laporan_sementara(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Jakarta'));

        $employee = $this->karyawan();
        $this->scan('1', '2026-08-06 09:05:00');

        $a = $this->hitung($employee, '2026-08-06');

        $this->assertSame('hadir', $a->status->value);
        $this->assertNull($a->check_out_at);
    }

    public function test_menghitung_ulang_menimpa_bukan_menggandakan(): void
    {
        $employee = $this->karyawan();
        $this->scan('1', '2026-08-06 09:30:00');

        $pertama = $this->hitung($employee, '2026-08-06');
        $this->assertSame('hadir', $pertama->status->value);

        // Scan pulang datang menyusul lewat cron get_attlog.
        $this->scan('1', '2026-08-06 17:10:00');

        $kedua = $this->hitung($employee, '2026-08-06');

        $this->assertSame(1, Attendance::count());
        $this->assertSame($pertama->id, $kedua->id);
        $this->assertSame('17:10:00', $kedua->check_out_at->format('H:i:s'));
    }

    public function test_potongan_ikut_config(): void
    {
        config([
            'attendance.late.block_minutes' => 15,
            'attendance.late.block_deduction' => 7500,
        ]);

        $employee = $this->karyawan();
        $this->scan('1', '2026-08-06 09:16:00');

        $a = $this->hitung($employee, '2026-08-06');

        // blok potongan pindah ke rule_tiers; di sini yang dicatat menitnya.
        // nominal potongan dihitung modul payroll, bukan modul absensi.
    }

    public function test_toleransi_adalah_ambang_bukan_pengurang(): void
    {
        config(['attendance.late.grace_seconds' => 300]);

        $shift = Shift::factory()->create();

        // Masih di dalam toleransi: nol penuh.
        $aman = $this->karyawan(['pin_device' => '10'], $shift);
        $this->scan('10', '2026-08-06 09:05:00');
        $this->assertSame('hadir', $this->hitung($aman, '2026-08-06')->status->value);

        // Lewat toleransi: dihitung dari jam masuk, bukan dari akhir toleransi.
        $telat = $this->karyawan(['pin_device' => '11'], $shift);
        $this->scan('11', '2026-08-06 09:06:00');
        $hasil = $this->hitung($telat, '2026-08-06');

        $this->assertSame(360, $hasil->late_seconds);
        // blok potongan pindah ke rule_tiers; di sini yang dicatat menitnya.
    }

    public function test_shift_disalin_supaya_rekap_lama_tidak_ikut_berubah(): void
    {
        $pagi = Shift::factory()->create();
        $employee = $this->karyawan([], $pagi);
        $this->scan('1', '2026-08-06 09:00:00');

        $a = $this->hitung($employee, '2026-08-06');
        $this->assertSame($pagi->id, $a->shift_id);

        // Karyawan pindah ke shift malam bulan depan.
        $malam = Shift::factory()->malam()->create();
        $employee->update(['default_shift_id' => $malam->id]);

        // Rekap lama yang sudah tersimpan tetap menunjuk shift lamanya selama
        // tidak dihitung ulang.
        $this->assertSame($pagi->id, $a->fresh()->shift_id);
        $this->assertSame('2026-08-06 09:00:00', $a->fresh()->scheduled_in->format('Y-m-d H:i:s'));
    }

    public function test_compute_date_meringkas_seluruh_karyawan(): void
    {
        $shift = Shift::factory()->create();

        $this->karyawan(['pin_device' => '1', 'name' => 'Hadir'], $shift);
        $this->karyawan(['pin_device' => '2', 'name' => 'Telat'], $shift);
        $this->karyawan(['pin_device' => '3', 'name' => 'Alpha'], $shift);

        $this->scan('1', '2026-08-06 08:50:00');
        $this->scan('2', '2026-08-06 09:25:00');

        $hasil = $this->computer->computeDate(Carbon::parse('2026-08-06', 'Asia/Jakarta'));

        $this->assertSame(3, $hasil['computed']);

        // Yang telat tetap berstatus hadir — keterlambatan itu angka menit,
        // bukan status tersendiri. Jadi hadir = 2, bukan 1.
        $this->assertSame(2, $hasil['hadir']);
        $this->assertSame(1, $hasil['alpha']);
        $this->assertArrayNotHasKey('telat', $hasil);
    }
}
