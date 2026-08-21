<?php

namespace Tests\Feature;

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
 * `attendance:jelaskan` — kenapa rekap seseorang berbunyi seperti itu.
 *
 * Yang dijaga di sini bukan format tampilannya, tapi satu hal: scan yang
 * dibuang karena jatuh di luar jendela kerja HARUS kelihatan. Itu satu-satunya
 * kegagalan di alur ini yang tidak menimbulkan error sama sekali — rekapnya
 * tetap terlihat wajar, dan baru ketahuan kalau orangnya protes sendiri.
 *
 * Keluaran diperiksa lewat Artisan::output(), bukan expectsOutputToContain():
 * yang terakhir itu cuma bisa mencocokkan SATU substring per baris keluaran
 * (Mockery menyalurkan tiap panggilan doWrite ke satu ekspektasi saja), jadi
 * dua harapan yang kebetulan ada di baris yang sama akan gagal walaupun
 * keduanya benar-benar tercetak.
 */
class JelaskanAbsensiTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $karyawan;

    protected Shift $malam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);

        // Dipatok setelah jendela kerja tanggal uji tutup, supaya hasilnya
        // sudah boleh disimpulkan (lihat penjagaan di computeAssignment).
        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00', 'Asia/Jakarta'));

        $this->malam = Shift::where('code', 'malam')->firstOrFail();

        $this->karyawan = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Uji Jelaskan',
            'pin_device' => '77',
            'default_shift_id' => $this->malam->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function jadwalkan(string $tanggal): RosterAssignment
    {
        $service = app(RosterService::class);

        return $service->assign(
            $service->findOrCreate(2026, 8),
            $this->karyawan,
            Carbon::parse($tanggal, 'Asia/Jakarta'),
            $this->malam->id,
        );
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

    protected function jelaskan(string $tanggal = '2026-08-21'): string
    {
        Artisan::call('attendance:compute', ['--from' => $tanggal, '--to' => $tanggal]);
        Artisan::call('attendance:jelaskan', ['pin' => '77', 'tanggal' => $tanggal]);

        return Artisan::output();
    }

    /**
     * Kasus nyata 21 Agustus 2026: shift malam dipendekkan jadi mulai 13:30
     * lewat jam khusus, jendelanya ikut bergeser jadi mulai 09:30, dan scan
     * 07:54 pagi hilang tanpa jejak — yang masuk rekap justru scan 13:00 yang
     * tidak disengaja.
     */
    public function test_scan_di_luar_jendela_dilaporkan_bukan_dibuang_diam_diam(): void
    {
        $this->jadwalkan('2026-08-21');
        Artisan::call('roster:jam-khusus', [
            'tanggal' => '2026-08-21', 'shift' => 'malam',
            'mulai' => '13:30', 'selesai' => '22:30',
        ]);

        $this->scan('2026-08-21 07:54:07');
        $this->scan('2026-08-21 13:00:12');
        $this->scan('2026-08-21 22:35:00');

        $keluaran = $this->jelaskan();

        $this->assertStringContainsString('07:54:07', $keluaran);
        $this->assertStringContainsString('DIABAIKAN', $keluaran);
        $this->assertStringContainsString('sebelum jendela Shift Malam dibuka (09:30:00)', $keluaran);
        $this->assertStringContainsString('1 scan tidak masuk rekap', $keluaran);

        // Jam yang benar-benar dipakai harus tertulis, bukan cuma jam master —
        // selisih inilah yang menjelaskan kenapa scan paginya terbuang.
        $this->assertStringContainsString('jam khusus 13:30–22:30', $keluaran);
        $this->assertStringContainsString('master 17:00–01:00', $keluaran);
    }

    /** Scan yang akhirnya dipakai ditandai perannya, biar tidak perlu ditebak. */
    public function test_jam_masuk_dan_jam_pulang_ditandai(): void
    {
        $this->jadwalkan('2026-08-21');

        $this->scan('2026-08-21 16:50:00');
        $this->scan('2026-08-22 00:55:00');

        $keluaran = $this->jelaskan();

        $this->assertStringContainsString('JAM MASUK', $keluaran);
        $this->assertStringContainsString('JAM PULANG', $keluaran);

        // Scan pulang shift malam jatuh di tanggal berikutnya. Tanpa penanda,
        // pembacanya mengira salah baca — ini sudah jadi konvensi di tampilan
        // lain, jadi diagnosis pun harus ikut.
        $this->assertStringContainsString('00:55:00 (+1 hari)', $keluaran);

        $this->assertStringNotContainsString('DIABAIKAN', $keluaran);
    }

    /** Tanpa roster, shift-nya cuma tebakan — dan itu harus dikatakan. */
    public function test_shift_tebakan_ditandai_sebagai_tebakan(): void
    {
        $this->scan('2026-08-21 16:50:00');

        $this->assertStringContainsString('TEBAKAN', $this->jelaskan());
    }

    public function test_pin_tidak_dikenal_ditolak(): void
    {
        $this->artisan('attendance:jelaskan 9999 2026-08-21')->assertFailed();
    }
}
