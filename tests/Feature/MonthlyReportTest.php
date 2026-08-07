<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Services\Attendance\AttendanceComputer;
use App\Services\Attendance\MonthlyReport;
use App\Services\Attendance\MonthlyReportExcel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class MonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    protected Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        // Dipatok di bulan berikutnya supaya seluruh Agustus sudah lewat dan
        // jendela kerjanya pasti tertutup.
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:00:00', 'Asia/Jakarta'));

        $this->shift = Shift::factory()->create(['name' => 'Shift 1']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function scan(string $pin, string $waktu): void
    {
        $at = Carbon::parse($waktu, 'Asia/Jakarta');

        AttendanceLog::create([
            'cloud_id' => 'GQ5179086',
            'pin' => $pin,
            'scanned_at' => $at,
            'scan_minute' => $at->copy()->startOfMinute(),
            'source' => 'webhook',
        ]);
    }

    protected function hitung(string ...$tanggal): void
    {
        $computer = app(AttendanceComputer::class);

        foreach ($tanggal as $t) {
            $computer->computeDate(Carbon::parse($t, 'Asia/Jakarta'));
        }
    }

    protected function skenario(): Employee
    {
        $budi = Employee::factory()->create([
            'pin_device' => '1', 'name' => 'Budi', 'default_shift_id' => $this->shift->id,
            
        ]);

        // 3 Agustus: tepat waktu.
        $this->scan('1', '2026-08-03 08:55:00');
        // 4 Agustus: telat 7 menit -> 1 blok -> Rp 5.000.
        $this->scan('1', '2026-08-04 09:07:00');
        // 5 Agustus: telat 23 menit -> 3 blok -> Rp 15.000.
        $this->scan('1', '2026-08-05 09:23:00');
        // 6 Agustus: tidak scan -> alpha.

        $this->hitung('2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06');

        return $budi;
    }

    // ---------------------------------------------------------------- agregasi

    public function test_ringkasan_menjumlahkan_status_dan_potongan(): void
    {
        $this->skenario();

        $baris = MonthlyReport::for(2026, 8)->ringkasan()->sole();

        $this->assertSame('Budi', $baris['nama']);
        // Tiga hari masuk, dua di antaranya telat. Hadir dan telat dihitung
        // di dimensi berbeda, jadi angkanya memang tumpang tindih.
        $this->assertSame(3, $baris['hadir']);
        $this->assertSame(2, $baris['telat']);
        $this->assertSame(1, $baris['alpha']);
        $this->assertSame(4, $baris['hari_tercatat']);
        // nominal potongan pindah ke slip gaji.
        
        
    }

    /**
     * Karyawan tanpa catatan harus tetap muncul dengan angka nol. Kalau dia
     * hilang dari laporan, tidak ada yang menyadari dia terlewat.
     */
    public function test_karyawan_tanpa_catatan_tetap_muncul(): void
    {
        Employee::factory()->create([
            'pin_device' => '9', 'name' => 'Belum Pernah', 'default_shift_id' => $this->shift->id,
        ]);

        $baris = MonthlyReport::for(2026, 8)->ringkasan()->sole();

        $this->assertSame(0, $baris['hari_tercatat']);
        // nominal potongan pindah ke slip gaji.
        
    }

    public function test_karyawan_nonaktif_tidak_masuk_laporan(): void
    {
        Employee::factory()->nonaktif()->create(['default_shift_id' => $this->shift->id]);

        $this->assertCount(0, MonthlyReport::for(2026, 8)->ringkasan());
    }

    public function test_bulan_lain_tidak_ikut_terhitung(): void
    {
        $this->skenario();

        // Juli tidak punya data sama sekali.
        $juli = MonthlyReport::for(2026, 7)->ringkasan()->sole();

        $this->assertSame(0, $juli['hari_tercatat']);
        // nominal potongan pindah ke slip gaji.
    }

    public function test_rincian_harian_satu_baris_per_tanggal(): void
    {
        $this->skenario();

        $rincian = MonthlyReport::for(2026, 8)->rincian();

        $this->assertCount(4, $rincian);
        $this->assertSame('2026-08-03', $rincian->first()['tanggal']->toDateString());
        $this->assertSame('Budi', $rincian->first()['nama']);
    }

    public function test_total_menjumlahkan_seluruh_karyawan(): void
    {
        $this->skenario();

        Employee::factory()->create([
            'pin_device' => '2', 'name' => 'Sari',
            'default_shift_id' => $this->shift->id,
        ]);
        $this->scan('2', '2026-08-03 08:50:00');
        $this->hitung('2026-08-03');

        $total = MonthlyReport::for(2026, 8)->total();

        $this->assertSame(2, $total['karyawan']);
        
        // nominal potongan pindah ke slip gaji.
        
    }

    public function test_durasi_dibaca_manusia(): void
    {
        $this->assertSame('-', MonthlyReport::durasi(0));
        $this->assertSame('45d', MonthlyReport::durasi(45));
        $this->assertSame('7m 0d', MonthlyReport::durasi(420));
        $this->assertSame('1j 10m', MonthlyReport::durasi(4200));
    }

    // ------------------------------------------------------------------- excel

    public function test_berkas_excel_valid_dan_isinya_cocok(): void
    {
        $this->skenario();

        $path = tempnam(sys_get_temp_dir(), 'uji').'.xlsx';

        (new MonthlyReportExcel(MonthlyReport::for(2026, 8)))->simpan($path);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        // Dibuka kembali untuk memastikan berkasnya benar-benar xlsx yang sah,
        // bukan sekadar berkas yang terbentuk.
        $spreadsheet = IOFactory::load($path);

        $this->assertSame(['Ringkasan', 'Rincian Harian'], $spreadsheet->getSheetNames());

        $ringkasan = $spreadsheet->getSheetByName('Ringkasan');
        $this->assertStringContainsString('Agustus 2026', (string) $ringkasan->getCell('A1')->getValue());
        $this->assertSame('PIN', $ringkasan->getCell('A4')->getValue());
        $this->assertSame('Budi', $ringkasan->getCell('B5')->getValue());
        $this->assertSame(3, $ringkasan->getCell('D5')->getValue());       // hadir
        $this->assertSame(2, $ringkasan->getCell('E5')->getValue());       // telat
        $this->assertSame(0, $ringkasan->getCell('F5')->getValue());       // pulang cepat
        $this->assertSame(1, $ringkasan->getCell('G5')->getValue());       // alpha
        $this->assertSame(0, $ringkasan->getCell('K5')->getValue());       // libur

        // Baris total pakai rumus, bukan angka mati.
        $this->assertSame('TOTAL', $ringkasan->getCell('A6')->getValue());
        $this->assertStringStartsWith('=SUM(', (string) $ringkasan->getCell('L6')->getValue());

        $rincian = $spreadsheet->getSheetByName('Rincian Harian');
        $this->assertSame('Tanggal', $rincian->getCell('A1')->getValue());
        $this->assertSame('2026-08-03', $rincian->getCell('A2')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($path);
    }

    /**
     * fromArray() bawaannya membandingkan longgar dengan null, dan di PHP
     * 0 == null bernilai true, sehingga setiap angka nol ditulis jadi sel
     * kosong. Di laporan gajian, "0 alpha" dan "Rp 0 potongan" jadi tampak
     * seperti data yang tidak terisi.
     */
    public function test_angka_nol_ditulis_sebagai_nol_bukan_sel_kosong(): void
    {
        Employee::factory()->create([
            'pin_device' => '9', 'name' => 'Rajin Sekali',
            'default_shift_id' => $this->shift->id,
        ]);
        $this->scan('9', '2026-08-03 08:50:00');
        $this->hitung('2026-08-03');

        $path = tempnam(sys_get_temp_dir(), 'uji').'.xlsx';
        (new MonthlyReportExcel(MonthlyReport::for(2026, 8)))->simpan($path);

        $sheet = IOFactory::load($path)->getSheetByName('Ringkasan');

        // Orang ini tidak pernah telat: semua angka telat harus nol, terlihat.
        $this->assertSame(0, $sheet->getCell('E5')->getValue(), 'telat');
        $this->assertSame(0, $sheet->getCell('F5')->getValue(), 'pulang cepat');
        $this->assertSame(0, $sheet->getCell('G5')->getValue(), 'alpha');
        $this->assertSame(0, $sheet->getCell('K5')->getValue(), 'libur');
        $this->assertSame(0, $sheet->getCell('N5')->getValue(), 'lembur');

        @unlink($path);
    }

    public function test_bulan_kosong_tetap_menghasilkan_berkas(): void
    {
        // Bulan tanpa karyawan sama sekali tidak boleh bikin proses meledak.
        $path = tempnam(sys_get_temp_dir(), 'uji').'.xlsx';

        (new MonthlyReportExcel(MonthlyReport::for(2026, 1)))->simpan($path);

        $this->assertFileExists($path);
        $this->assertSame(['Ringkasan', 'Rincian Harian'], IOFactory::load($path)->getSheetNames());

        @unlink($path);
    }

    // ------------------------------------------------------------------ halaman

    public function test_halaman_rekap_menampilkan_angka(): void
    {
        $this->skenario();

        $this->actingAs(User::factory()->create())
            ->get('/laporan?bulan=2026-08')
            ->assertOk()
            ->assertSee('Agustus 2026')
            ->assertSee('Budi')
            // Halaman rekap absensi melaporkan fakta, bukan rupiah. Angka
            // uangnya ada di slip gaji, yang punya sumber perhitungan sendiri.
            ->assertSee('Total telat')
            ->assertSee('Total lembur');
    }

    public function test_unduhan_excel_terkirim_dengan_nama_benar(): void
    {
        $this->skenario();

        $response = $this->actingAs(User::factory()->create())->get('/laporan/excel?bulan=2026-08');

        $response->assertOk();
        $response->assertDownload('absensi-2026-08.xlsx');
    }

    public function test_tamu_tidak_bisa_mengunduh_laporan(): void
    {
        // Laporan memuat gaji semua karyawan, jadi tidak boleh bocor.
        $this->get('/laporan/excel?bulan=2026-08')->assertRedirect(route('login'));
        $this->get('/laporan')->assertRedirect(route('login'));
    }

    public function test_manajer_boleh_melihat_dan_mengunduh(): void
    {
        $manajer = User::factory()->create();

        $this->actingAs($manajer)->get('/laporan')->assertOk();
        $this->actingAs($manajer)->get('/laporan/excel')->assertOk();
    }

    public function test_bulan_ngawur_di_url_tidak_bikin_error(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/laporan?bulan=2026-99')
            ->assertOk()
            // Jatuh kembali ke bulan berjalan.
            ->assertSee('September 2026');
    }

    public function test_bulan_tanpa_parameter_memakai_bulan_berjalan(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/laporan')
            ->assertOk()
            ->assertSee('September 2026');
    }

    // -------------------------------------------------------- harian & mingguan

    public function test_rekap_harian_cuma_memuat_satu_tanggal(): void
    {
        $this->skenario();

        $report = MonthlyReport::forDay(Carbon::parse('2026-08-04', 'Asia/Jakarta'));
        $baris = $report->ringkasan()->sole();

        $this->assertSame(1, $baris['hari_tercatat']);
        $this->assertSame(1, $baris['telat']);
        $this->assertSame('2026-08-04', $report->periodeAwal()->toDateString());
        $this->assertSame('2026-08-04', $report->periodeAkhir()->toDateString());
    }

    public function test_rekap_mingguan_memuat_senin_sampai_minggu(): void
    {
        $this->skenario();

        // 3-5 Agustus 2026 jatuh di pekan Senin 3 Agustus - Minggu 9 Agustus.
        $report = MonthlyReport::forWeek(Carbon::parse('2026-08-05', 'Asia/Jakarta'));

        $this->assertSame('2026-08-03', $report->periodeAwal()->toDateString());
        $this->assertSame('2026-08-09', $report->periodeAkhir()->toDateString());

        $baris = $report->ringkasan()->sole();
        $this->assertSame(4, $baris['hari_tercatat']);
    }

    public function test_halaman_rekap_mingguan_dan_harian_terbuka(): void
    {
        $this->skenario();
        $manajer = User::factory()->create();

        $this->actingAs($manajer)
            ->get('/laporan?tampilan=harian&tanggal=2026-08-04')
            ->assertOk()
            ->assertSee('Budi');

        $this->actingAs($manajer)
            ->get('/laporan?tampilan=mingguan&minggu=2026-W32')
            ->assertOk()
            ->assertSee('Budi');
    }

    // --------------------------------------------------------------- teks WA

    public function test_teks_whatsapp_memuat_nama_dan_total(): void
    {
        $this->skenario();

        $teks = MonthlyReport::for(2026, 8)->teksWhatsApp();

        $this->assertStringContainsString('Agustus 2026', $teks);
        $this->assertStringContainsString('Budi', $teks);
        $this->assertStringContainsString('*Total:*', $teks);
        $this->assertStringContainsString('1 karyawan', $teks);
    }

    public function test_teks_whatsapp_periode_kosong_tidak_meledak(): void
    {
        $teks = MonthlyReport::for(2026, 1)->teksWhatsApp();

        $this->assertStringContainsString('Belum ada data', $teks);
    }
}
