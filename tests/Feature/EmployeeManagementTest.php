<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Shift;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Shift::factory()->create(['name' => 'Shift 1']);
        Shift::factory()->malam()->create(['name' => 'Shift 2']);
    }

    protected function csv(string $isi): string
    {
        $path = tempnam(sys_get_temp_dir(), 'karyawan').'.csv';
        file_put_contents($path, $isi);

        return $path;
    }

    // ---------------------------------------------------------------- nomor HP

    /**
     * @param  string|null  $masukan
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nomorHp')]
    public function test_nomor_hp_diseragamkan(?string $masukan, ?string $diharap): void
    {
        $this->assertSame($diharap, PhoneNumber::normalize($masukan));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function nomorHp(): array
    {
        return [
            'awalan nol' => ['081234567890', '6281234567890'],
            'pakai strip' => ['0812-3456-7890', '6281234567890'],
            'pakai plus' => ['+62 812 3456 7890', '6281234567890'],
            'sudah 62' => ['6281234567890', '6281234567890'],
            'tanpa awalan' => ['81234567890', '6281234567890'],
            'ada spasi' => [' 0812 3456 7890 ', '6281234567890'],
            'kosong' => ['', null],
            'null' => [null, null],
            'bukan angka' => ['tidak ada', null],
        ];
    }

    public function test_nomor_hp_diseragamkan_lewat_model(): void
    {
        // Diseragamkan di model, jadi jalur mana pun dapat hasil yang sama.
        $employee = Employee::factory()->create(['phone' => '0812-3456-7890']);

        $this->assertSame('6281234567890', $employee->fresh()->phone);
    }

    public function test_nomor_hp_ditampilkan_dalam_bentuk_lokal(): void
    {
        $this->assertSame('0812-3456-7890', PhoneNumber::forDisplay('6281234567890'));
    }

    // ------------------------------------------------------------ employee:add

    public function test_menambah_karyawan_lewat_opsi(): void
    {
        $this->artisan('employee:add', [
            '--pin' => '7',
            '--name' => 'Budi',
            '--phone' => '081234567890',
            '--shift' => 'Shift 1',
            '--salary' => '3000000',
            '--joined' => '2026-08-01',
        ])->assertSuccessful();

        $employee = Employee::sole();

        $this->assertSame('7', $employee->pin_device);
        $this->assertSame('Budi', $employee->name);
        $this->assertSame('6281234567890', $employee->phone);
        $this->assertSame(3_000_000, $employee->baseSalaryOn(now()));
        $this->assertSame('Shift 1', $employee->defaultShift->name);
        $this->assertTrue($employee->is_active);
    }

    public function test_pin_ganda_ditolak(): void
    {
        Employee::factory()->create(['pin_device' => '7', 'name' => 'Budi']);

        $this->artisan('employee:add', ['--pin' => '7', '--name' => 'Sari', '--shift' => 'Shift 1'])
            ->expectsOutputToContain('sudah dipakai Budi')
            ->assertFailed();

        $this->assertSame(1, Employee::count());
    }

    /**
     * Mesin mengirim PIN sebagai string, jadi "07" dan "7" adalah dua orang.
     */
    public function test_pin_dengan_nol_di_depan_dianggap_berbeda(): void
    {
        Employee::factory()->create(['pin_device' => '7']);

        $this->artisan('employee:add', ['--pin' => '07', '--name' => 'Sari', '--shift' => 'Shift 1'])
            ->assertSuccessful();

        $this->assertSame(2, Employee::count());
        $this->assertNotNull(Employee::where('pin_device', '07')->first());
    }

    public function test_shift_tidak_dikenal_ditolak(): void
    {
        $this->artisan('employee:add', ['--pin' => '7', '--name' => 'Budi', '--shift' => 'Shift 9'])
            ->expectsOutputToContain('tidak ditemukan')
            ->assertFailed();

        $this->assertSame(0, Employee::count());
    }

    public function test_nama_kosong_ditolak(): void
    {
        $this->artisan('employee:add', ['--pin' => '7', '--name' => '  ', '--shift' => 'Shift 1'])
            ->assertFailed();

        $this->assertSame(0, Employee::count());
    }

    public function test_memberi_tahu_kalau_ada_scan_lama_dengan_pin_itu(): void
    {
        $at = Carbon::parse('2026-08-05 09:00:00', 'Asia/Jakarta');

        AttendanceLog::create([
            'cloud_id' => 'XXXXX', 'pin' => '7', 'scanned_at' => $at,
            'scan_minute' => $at->copy()->startOfMinute(), 'source' => 'webhook',
        ]);

        $this->artisan('employee:add', ['--pin' => '7', '--name' => 'Budi', '--shift' => 'Shift 1'])
            ->expectsOutputToContain('1 scan lama')
            ->assertSuccessful();
    }

    // --------------------------------------------------------- employee:import

    public function test_impor_csv_membuat_karyawan(): void
    {
        $path = $this->csv(
            "pin_device,name,phone,shift,base_salary,joined_at\n".
            "1,Budi,081234567890,Shift 1,3000000,2026-08-01\n".
            "2,Sari,0857-1234-5678,Shift 2,3200000,2026-08-01\n"
        );

        $this->artisan('employee:import', ['file' => $path])->assertSuccessful();

        $this->assertSame(2, Employee::count());

        $sari = Employee::where('pin_device', '2')->sole();
        $this->assertSame('6285712345678', $sari->phone);
        $this->assertSame('Shift 2', $sari->defaultShift->name);
    }

    public function test_impor_ulang_memperbarui_bukan_menggandakan(): void
    {
        $awal = $this->csv("pin_device,name,shift\n1,Budi,Shift 1\n");
        $this->artisan('employee:import', ['file' => $awal])->assertSuccessful();

        $koreksi = $this->csv("pin_device,name,shift\n1,Budi Santoso,Shift 2\n");
        $this->artisan('employee:import', ['file' => $koreksi])->assertSuccessful();

        $this->assertSame(1, Employee::count());

        $employee = Employee::sole();
        $this->assertSame('Budi Santoso', $employee->name);
        $this->assertSame('Shift 2', $employee->defaultShift->name);
    }

    public function test_dry_run_tidak_menyimpan(): void
    {
        $path = $this->csv("pin_device,name,shift\n1,Budi,Shift 1\n");

        $this->artisan('employee:import', ['file' => $path, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0, Employee::count());
    }

    public function test_kolom_wajib_hilang_ditolak(): void
    {
        $path = $this->csv("nama,shift\nBudi,Shift 1\n");

        $this->artisan('employee:import', ['file' => $path])
            ->expectsOutputToContain('Kolom wajib tidak ada')
            ->assertFailed();
    }

    public function test_baris_bermasalah_dilaporkan_tanpa_menghentikan_sisanya(): void
    {
        $path = $this->csv(
            "pin_device,name,shift\n".
            "1,Budi,Shift 1\n".
            ",Tanpa PIN,Shift 1\n".        // pin kosong
            "3,Andi,Shift 9\n".            // shift tidak ada
            "4,Sari,Shift 2\n"
        );

        $this->artisan('employee:import', ['file' => $path])->assertFailed();

        // Baris yang sah tetap masuk.
        $this->assertSame(2, Employee::count());
        $this->assertNotNull(Employee::where('pin_device', '1')->first());
        $this->assertNotNull(Employee::where('pin_device', '4')->first());
    }

    public function test_tanggal_bergabung_tidak_sah_ditolak(): void
    {
        $path = $this->csv("pin_device,name,shift,joined_at\n1,Budi,Shift 1,01-08-2026\n");

        $this->artisan('employee:import', ['file' => $path])
            ->expectsOutputToContain('bukan tanggal')
            ->assertFailed();

        $this->assertSame(0, Employee::count());
    }

    public function test_bom_dari_excel_tidak_merusak_baris_judul(): void
    {
        // Excel menyisipkan BOM di awal berkas, dan itu bikin kolom pertama
        // tidak terbaca kalau tidak dibuang.
        $path = $this->csv("\u{FEFF}pin_device,name,shift\n1,Budi,Shift 1\n");

        $this->artisan('employee:import', ['file' => $path])->assertSuccessful();

        $this->assertSame('1', Employee::sole()->pin_device);
    }

    public function test_baris_kosong_di_akhir_berkas_diabaikan(): void
    {
        $path = $this->csv("pin_device,name,shift\n1,Budi,Shift 1\n\n\n");

        $this->artisan('employee:import', ['file' => $path])->assertSuccessful();

        $this->assertSame(1, Employee::count());
    }

    public function test_berkas_tidak_ada_ditolak(): void
    {
        $this->artisan('employee:import', ['file' => '/tidak/ada.csv'])->assertFailed();
    }

    public function test_berkas_contoh_bawaan_bisa_diimpor(): void
    {
        // Menjaga berkas contoh tetap sinkron dengan format yang diterima.
        $this->artisan('employee:import', [
            'file' => database_path('karyawan-contoh.csv'),
        ])->assertSuccessful();

        $this->assertSame(3, Employee::count());
        $this->assertSame('6281299998888', Employee::where('pin_device', '3')->sole()->phone);
    }

    // ----------------------------------------------------------- employee:list

    public function test_daftar_kosong_memberi_petunjuk(): void
    {
        $this->artisan('employee:list')
            ->expectsOutputToContain('Belum ada karyawan terdaftar')
            ->assertSuccessful();
    }

    public function test_daftar_menyembunyikan_nonaktif_kecuali_diminta(): void
    {
        Employee::factory()->create(['name' => 'Aktif']);
        Employee::factory()->nonaktif()->create(['name' => 'Resign']);

        $this->artisan('employee:list')->doesntExpectOutputToContain('Resign')->assertSuccessful();
        $this->artisan('employee:list', ['--all' => true])->expectsOutputToContain('Resign')->assertSuccessful();
    }

    public function test_daftar_memperingatkan_karyawan_tanpa_shift(): void
    {
        Employee::factory()->tanpaShift()->create(['name' => 'Belum Dijadwal']);

        $this->artisan('employee:list')
            ->expectsOutputToContain('tidak akan masuk rekap')
            ->assertSuccessful();
    }
}
