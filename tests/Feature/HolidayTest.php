<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use App\Services\Attendance\AttendanceComputer;
use App\Services\Attendance\MonthlyReport;
use App\Support\DayOfWeek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HolidayTest extends TestCase
{
    use RefreshDatabase;

    protected Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        // Dipatok jauh setelah tanggal uji supaya jendela kerjanya sudah lewat.
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:00:00', 'Asia/Jakarta'));

        $this->shift = Shift::factory()->create(['name' => 'Shift 1']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function karyawan(array $state = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'pin_device' => '1', 'name' => 'Budi',
            'default_shift_id' => $this->shift->id, 
        ], $state));
    }

    protected function scan(string $pin, string $waktu): void
    {
        $at = Carbon::parse($waktu, 'Asia/Jakarta');

        AttendanceLog::create([
            'cloud_id' => 'GQ5179086', 'pin' => $pin, 'scanned_at' => $at,
            'scan_minute' => $at->copy()->startOfMinute(), 'source' => 'sync',
        ]);
    }

    protected function hitung(string $tanggal): ?Attendance
    {
        return app(AttendanceComputer::class)->computeEmployee(
            Employee::sole(), Carbon::parse($tanggal, 'Asia/Jakarta'),
        )->first();
    }

    // --------------------------------------------------------- hari kalender

    public function test_nomor_hari_sesuai_kalender(): void
    {
        // 2026-08-09 itu Minggu, 2026-08-10 Senin.
        $this->assertSame(0, Carbon::parse('2026-08-09', 'Asia/Jakarta')->dayOfWeek);
        $this->assertSame(1, Carbon::parse('2026-08-10', 'Asia/Jakarta')->dayOfWeek);
    }

    // ------------------------------------------------------ libur mingguan

    public function test_libur_mingguan_tidak_dihitung_alpha(): void
    {
        $this->karyawan(['preferred_off_days' => [0]]); // libur tiap Minggu

        // 2026-08-09 adalah hari Minggu, dan dia tidak scan.
        $a = $this->hitung('2026-08-09');

        $this->assertSame('libur', $a->status->value);
        $this->assertNull($a->check_in_at);
        $this->assertSame(0, $a->late_minutes);
    }

    /**
     * SEMENTARA: tanpa RosterAssignment nyata, hari kerja tanpa scan sekarang
     * jatuh ke libur juga (lihat AttendanceComputer::resolveStatus), bukan
     * cuma hari off_days. Jadi tesnya tidak lagi bisa membedakan "hari kerja"
     * dari "hari libur mingguan" selama roster belum dipakai — begitu roster
     * dipakai penuh dan fallback ini dicabut, tes ini harus balik ke 'alpha'.
     */
    public function test_hari_kerja_tanpa_scan_tetap_libur_sementara_tanpa_roster(): void
    {
        $this->karyawan(['preferred_off_days' => [0]]);

        // Senin, bukan hari liburnya — tapi tanpa roster tetap libur (sementara).
        $this->assertSame('libur', $this->hitung('2026-08-10')->status->value);
    }

    /**
     * SEMENTARA: lihat catatan di atas — tanpa roster, perbedaan off_days null
     * vs off_days terisi tidak lagi kelihatan dari status (sama-sama libur).
     */
    public function test_off_days_kosong_berarti_tidak_punya_libur_mingguan(): void
    {
        // null harus berarti "belum diatur", bukan "libur setiap hari".
        $this->karyawan(['preferred_off_days' => null]);

        $this->assertSame('libur', $this->hitung('2026-08-09')->status->value);
    }

    public function test_bisa_punya_lebih_dari_satu_hari_libur(): void
    {
        $this->karyawan(['preferred_off_days' => [0, 3]]); // Minggu dan Rabu

        $this->assertSame('libur', $this->hitung('2026-08-09')->status->value); // Minggu
        $this->assertSame('libur', $this->hitung('2026-08-12')->status->value); // Rabu

        // SEMENTARA: Selasa harusnya hari kerja biasa, tapi tanpa roster
        // nyata tetap libur juga — lihat catatan di atas.
        $this->assertSame('libur', $this->hitung('2026-08-11')->status->value); // Selasa
    }

    /**
     * Masuk di hari libur itu membantu di luar jadwal, bukan telat dari jadwal
     * yang memang tidak berlaku hari itu.
     */
    public function test_masuk_di_hari_libur_dihitung_hadir_tanpa_potongan(): void
    {
        $this->karyawan(['preferred_off_days' => [0]]);

        // Minggu, datang jam 11 padahal jadwal normalnya 09:00.
        $this->scan('1', '2026-08-09 11:00:00');

        $a = $this->hitung('2026-08-09');

        $this->assertSame('hadir', $a->status->value);
        $this->assertSame(0, $a->late_seconds);
        $this->assertSame(0, $a->late_seconds);
        $this->assertSame(0, $a->late_minutes);
        $this->assertNotNull($a->check_in_at);
    }

    // -------------------------------------------------------- libur bersama

    public function test_libur_bersama_berlaku_untuk_semua(): void
    {
        $this->karyawan(['preferred_off_days' => []]);

        Holiday::create(['date' => '2026-08-17', 'name' => 'HUT RI', 'is_closed' => true]);

        $this->assertSame('libur', $this->hitung('2026-08-17')->status->value);
    }

    /**
     * Tanggal merah yang kafenya tetap buka tidak menghapus kewajiban masuk.
     *
     * SEMENTARA: tanpa RosterAssignment nyata, tidak-scan tetap jatuh ke
     * libur juga (lihat catatan di test_hari_kerja_tanpa_scan_...), jadi
     * assert-nya ikut disesuaikan sampai fallback ini dicabut.
     */
    public function test_libur_yang_kafenya_tetap_buka_tidak_meliburkan(): void
    {
        $this->karyawan(['preferred_off_days' => []]);

        Holiday::create(['date' => '2026-08-17', 'name' => 'HUT RI', 'is_closed' => false]);

        $this->assertSame('libur', $this->hitung('2026-08-17')->status->value);
    }

    public function test_libur_bersama_dan_libur_mingguan_bisa_bertumpuk(): void
    {
        $this->karyawan(['preferred_off_days' => [0]]);

        Holiday::create(['date' => '2026-08-09', 'name' => 'Libur Bersama', 'is_closed' => true]);

        // Tidak boleh jadi masalah, tetap satu baris dengan status libur.
        $this->assertSame('libur', $this->hitung('2026-08-09')->status->value);
        $this->assertSame(1, Attendance::count());
    }

    // ------------------------------------------------------------- laporan

    public function test_libur_terhitung_terpisah_dari_alpha_di_laporan(): void
    {
        $this->karyawan(['preferred_off_days' => [0]]);

        $computer = app(AttendanceComputer::class);

        // 9 Agustus Minggu (libur), 10 Agustus Senin (tanpa roster nyata jadi
        // libur juga, sementara), 11 Agustus hadir.
        $this->scan('1', '2026-08-11 08:50:00');

        foreach (['2026-08-09', '2026-08-10', '2026-08-11'] as $tanggal) {
            $computer->computeDate(Carbon::parse($tanggal, 'Asia/Jakarta'));
        }

        $baris = MonthlyReport::for(2026, 8)->ringkasan()->sole();

        $this->assertSame(2, $baris['libur']);
        $this->assertSame(0, $baris['alpha']);
        $this->assertSame(1, $baris['hadir']);
        $this->assertSame(0, $baris['telat']);
    }

    // ------------------------------------------------------------- perintah

    public function test_perintah_holiday_add_dan_list(): void
    {
        $this->artisan('holiday', ['aksi' => 'add', 'tanggal' => '2026-08-17', 'nama' => ['HUT', 'RI']])
            ->assertSuccessful();

        $holiday = Holiday::sole();
        $this->assertSame('HUT RI', $holiday->name);
        $this->assertTrue($holiday->is_closed);

        $this->artisan('holiday', ['aksi' => 'list'])
            ->expectsOutputToContain('HUT RI')
            ->assertSuccessful();
    }

    public function test_perintah_holiday_menolak_tanggal_ngawur(): void
    {
        $this->artisan('holiday', ['aksi' => 'add', 'tanggal' => '2026-02-31', 'nama' => ['Ngawur']])
            ->assertFailed();

        $this->assertSame(0, Holiday::count());
    }

    public function test_perintah_holiday_remove(): void
    {
        Holiday::create(['date' => '2026-08-17', 'name' => 'HUT RI']);

        $this->artisan('holiday', ['aksi' => 'remove', 'tanggal' => '2026-08-17'])->assertSuccessful();

        $this->assertSame(0, Holiday::count());
    }

    public function test_perintah_holiday_aksi_tidak_dikenal(): void
    {
        $this->artisan('holiday', ['aksi' => 'ngaco'])->assertFailed();
    }

    public function test_employee_edit_mengatur_libur_mingguan(): void
    {
        $this->karyawan();

        $this->artisan('employee:edit', ['pin' => '1', '--off-days' => 'minggu,rabu'])
            ->assertSuccessful();

        $this->assertSame([0, 3], Employee::sole()->offDays());
    }

    public function test_employee_edit_bisa_mengosongkan_libur(): void
    {
        $this->karyawan(['preferred_off_days' => [0]]);

        $this->artisan('employee:edit', ['pin' => '1', '--off-days' => '-'])->assertSuccessful();

        $this->assertSame([], Employee::sole()->offDays());
    }

    public function test_employee_edit_menolak_pin_tidak_ada(): void
    {
        $this->artisan('employee:edit', ['pin' => '999', '--name' => 'Siapa'])->assertFailed();
    }

    public function test_employee_toggle_menonaktifkan_dan_mengaktifkan(): void
    {
        $this->karyawan();

        $this->artisan('employee:toggle', ['pin' => '1'])->assertSuccessful();
        $this->assertFalse(Employee::sole()->is_active);

        $this->artisan('employee:toggle', ['pin' => '1', '--aktif' => true])->assertSuccessful();
        $this->assertTrue(Employee::sole()->is_active);
    }

    public function test_karyawan_nonaktif_tidak_lagi_dihitung(): void
    {
        $this->karyawan();

        // Sebelum dinonaktifkan dia tetap dihitung.
        $sebelum = app(AttendanceComputer::class)->computeDate(Carbon::parse('2026-08-10', 'Asia/Jakarta'));
        $this->assertSame(1, $sebelum['computed']);

        Attendance::query()->delete();

        $this->artisan('employee:toggle', ['pin' => '1'])->assertSuccessful();

        $sesudah = app(AttendanceComputer::class)->computeDate(Carbon::parse('2026-08-10', 'Asia/Jakarta'));

        // Karyawan nonaktif disaring sejak query, jadi tidak ikut diiterasi
        // sama sekali dan tidak menghasilkan baris apa pun.
        $this->assertSame(0, $sesudah['computed']);
        $this->assertSame(0, Attendance::count());
    }

    // ------------------------------------------------------- pembaca nama hari

    /**
     * @param  string  $masukan
     * @param  array<int, int>  $diharap
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('masukanHari')]
    public function test_nama_hari_dibaca_dari_teks_bebas(string $masukan, array $diharap): void
    {
        $this->assertSame($diharap, DayOfWeek::parse($masukan));
    }

    /**
     * @return array<string, array{0: string, 1: array<int, int>}>
     */
    public static function masukanHari(): array
    {
        return [
            'satu nama' => ['minggu', [0]],
            'beberapa nama' => ['senin,kamis', [1, 4]],
            'huruf besar' => ['MINGGU', [0]],
            'pakai spasi' => ['senin, jumat', [1, 5]],
            'pakai angka' => ['0,6', [0, 6]],
            'campur' => ['minggu,3', [0, 3]],
            'kembar dibuang' => ['minggu,minggu', [0]],
            'angka di luar rentang' => ['9', []],
            'tidak dikenal' => ['harilibur', []],
            'kosong' => ['', []],
        ];
    }

    public function test_daftar_hari_dibaca_manusia(): void
    {
        $this->assertSame('tidak ada', DayOfWeek::daftar([]));
        $this->assertSame('Minggu', DayOfWeek::daftar([0]));
        $this->assertSame('Senin, Kamis', DayOfWeek::daftar([4, 1]));
    }
}
