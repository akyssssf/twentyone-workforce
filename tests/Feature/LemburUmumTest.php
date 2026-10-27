<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\OvertimeRecord;
use App\Models\OvertimeRequest;
use App\Models\Shift;
use App\Models\User;
use App\Services\Requests\OvertimeCodeService;
use App\Services\Roster\RosterService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `lembur:umum` — lembur hari kerja bersama (General Cleaning) dari jam scan,
 * dengan pengali tarif.
 */
class LemburUmumTest extends TestCase
{
    use RefreshDatabase;

    protected const TANGGAL = '2026-10-27';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'Asia/Jakarta'));

        Shift::where('code', 'pagi')->update(['start_time' => '08:00:00', 'end_time' => '18:00:00']);
        Shift::where('code', 'malam')->update(['start_time' => '14:00:00', 'end_time' => '23:30:00']);

        User::factory()->create(['role' => UserRole::Admin]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function orang(string $nama, string $pin, ?string $shiftKode, array $scan): Employee
    {
        $e = Employee::factory()->create(['branch_id' => Branch::current()->id, 'name' => $nama, 'pin_device' => $pin]);

        if ($shiftKode !== null) {
            $service = app(RosterService::class);
            $service->assign($service->findOrCreate(2026, 10), $e, Carbon::parse(self::TANGGAL), Shift::where('code', $shiftKode)->value('id'));
        }

        foreach ($scan as $jam) {
            $at = Carbon::parse(self::TANGGAL.' '.$jam, 'Asia/Jakarta');
            AttendanceLog::create([
                'cloud_id' => 'UJI', 'employee_id' => $e->id, 'pin' => $pin,
                'scanned_at' => $at, 'scan_minute' => $at->copy()->startOfMinute(), 'source' => 'webhook',
            ]);
        }

        return $e;
    }

    public function test_pratinjau_menghitung_dari_scan_dan_tidak_menyimpan(): void
    {
        $this->orang('Libur Pulang', '11', null, ['08:05:00', '12:30:00']);     // libur: 08:05 → 12:30 = 4j25m
        $this->orang('Libur Lewat', '12', null, ['08:00:00', '13:40:00']);      // pulang lewat batas → dipotong 13:00 = 5j
        $this->orang('Libur Tanpa Pulang', '13', null, ['08:00:00']);           // tanpa scan pulang → sampai 13:00 = 5j
        $this->orang('Shift Malam', '14', 'malam', ['08:10:00', '13:52:00']);   // 08:10 → batas 13:00 = 4j50m
        $this->orang('Shift Pagi', '15', 'pagi', ['07:50:00', '18:02:00']);     // pagi biasa: DIBATASI lewat --abaikan, bukan lewat roster
        $this->orang('Malam Biasa', '16', 'malam', ['13:50:00']);               // baru scan setelah batas: bukan lembur pagi

        $this->assertSame(0, Artisan::call('lembur:umum', ['tanggal' => self::TANGGAL, '--abaikan' => ['15']]));
        $keluaran = Artisan::output();

        $this->assertStringContainsString('4j 25m', $keluaran);
        $this->assertStringContainsString('Libur Lewat', $keluaran);
        $this->assertStringContainsString('4j 50m', $keluaran);
        $this->assertStringNotContainsString('Shift Pagi', $keluaran, 'yang di-abaikan tidak ikut');
        $this->assertStringNotContainsString('Malam Biasa', $keluaran);
        $this->assertStringContainsString('4 orang', $keluaran);
        $this->assertStringContainsString('Belum disimpan', $keluaran);

        $this->assertSame(0, OvertimeRecord::count(), 'tanpa --simpan tidak ada yang berubah');
    }

    public function test_simpan_membuat_catatan_berpengali_dan_tidak_dobel(): void
    {
        $a = $this->orang('Libur Pulang', '11', null, ['08:05:00', '12:30:00']);

        $this->assertSame(0, Artisan::call('lembur:umum', ['tanggal' => self::TANGGAL, '--simpan' => true]));

        $r = OvertimeRecord::sole();
        $this->assertSame($a->id, $r->employee_id);
        $this->assertSame(265, (int) $r->payable_minutes, 'menit nyata, BUKAN digandakan');
        $this->assertSame(2.0, (float) $r->multiplier);
        $this->assertSame('confirmed', $r->status);
        $this->assertNotNull($r->confirmed_by, 'dilindungi dari perhitungan otomatis');
        $this->assertStringContainsString('08:05–12:30, tarif ×2', $r->note);

        // Dijalankan lagi: dilewati.
        $this->artisan('lembur:umum '.self::TANGGAL.' --simpan')
            ->expectsOutputToContain('Dilewati')
            ->assertSuccessful();
        $this->assertSame(1, OvertimeRecord::count());

        // Rekap hari itu memuat lembur nyata (265 menit), bukan 530.
        $this->assertSame(265, (int) Attendance::where('employee_id', $a->id)->sum('overtime_minutes'));
    }

    public function test_abaikan_dan_pin_menyaring_orang(): void
    {
        $this->orang('Satu', '11', null, ['08:05:00', '12:30:00']);
        $this->orang('Dua', '12', null, ['08:00:00', '12:00:00']);

        Artisan::call('lembur:umum', ['tanggal' => self::TANGGAL, '--abaikan' => ['12'], '--simpan' => true]);

        $this->assertSame(1, OvertimeRecord::count());
        $this->assertSame('Satu', OvertimeRecord::sole()->employee->name);
    }

    public function test_pengali_di_luar_batas_ditolak(): void
    {
        $this->assertSame(1, Artisan::call('lembur:umum', ['tanggal' => self::TANGGAL, '--pengali' => 9]));
    }

    /** Yang lupa scan ditolong dengan jam manual, tanpa kode. */
    public function test_tambah_manual_untuk_yang_lupa_scan(): void
    {
        $this->orang('Lupa Scan', '11', null, []);

        $this->artisan('lembur:umum '.self::TANGGAL.' --tambah=11:08:00-13:00 --simpan')->assertSuccessful();

        $r = OvertimeRecord::sole();
        $this->assertSame(300, (int) $r->payable_minutes);
        $this->assertSame(2.0, (float) $r->multiplier);
        $this->assertStringContainsString('08:00–13:00', $r->note);
    }

    public function test_tambah_menggantikan_hitungan_scan_dan_format_salah_ditolak(): void
    {
        $this->orang('Scan Keliru', '11', null, ['08:05:00', '12:30:00']);

        Artisan::call('lembur:umum', ['tanggal' => self::TANGGAL, '--tambah' => ['11:09:00-12:00'], '--simpan' => true]);
        $this->assertSame(180, (int) OvertimeRecord::sole()->payable_minutes);

        $this->assertSame(1, Artisan::call('lembur:umum', ['tanggal' => self::TANGGAL, '--tambah' => ['bukan-format']]));
    }

    // ------------------------------------------------------------------ kode

    /** Satu kode per orang, termasuk yang libur; dijalankan lagi tidak membuat kode kedua. */
    public function test_kode_dibuat_untuk_semua_orang_dan_tidak_dobel(): void
    {
        $this->orang('Libur', '11', null, []);
        $this->orang('Malam', '12', 'malam', []);

        $this->assertSame(0, Artisan::call('lembur:kode-umum', ['tanggal' => self::TANGGAL]));

        $kode = OvertimeRequest::pluck('secret_code');
        $this->assertCount(2, $kode);
        $this->assertCount(2, $kode->unique());
        $this->assertSame(2, OvertimeRecord::count());
        $this->assertSame([2.0, 2.0], OvertimeRecord::pluck('multiplier')->map(fn ($m) => (float) $m)->all());

        Artisan::call('lembur:kode-umum', ['tanggal' => self::TANGGAL]);
        $this->assertSame(2, OvertimeRequest::count(), 'kode tidak dibuat dua kali');
    }

    /**
     * Bawaan: kode dipakai OTOMATIS untuk yang punya scan, walau orangnya tidak
     * pernah memintanya. Orangnya diberi tahu; jam tetap dari scan; catatan
     * yang sudah ada DIISI (tidak dibuat catatan kedua).
     */
    public function test_kode_dipakai_otomatis_dan_orangnya_diberi_tahu(): void
    {
        Queue::fake();

        $sendiri = $this->orang('Masukkan Sendiri', '11', null, ['08:05:00', '12:30:00']);
        $otomatis = $this->orang('Tidak Minta', '12', null, ['08:00:00', '12:00:00']);
        $tidakDatang = $this->orang('Tidak Datang', '13', null, []);

        $akun = User::factory()->create(['role' => UserRole::Karyawan, 'employee_id' => $otomatis->id]);

        Artisan::call('lembur:kode-umum', ['tanggal' => self::TANGGAL]);

        $kodeSendiri = OvertimeRequest::query()
            ->whereHas('request', fn ($q) => $q->where('employee_id', $sendiri->id))
            ->value('secret_code');

        app(OvertimeCodeService::class)->activate($sendiri, $kodeSendiri);

        $this->artisan('lembur:umum '.self::TANGGAL)
            ->expectsOutputToContain('dipakai otomatis')
            ->assertSuccessful();

        Artisan::call('lembur:umum', ['tanggal' => self::TANGGAL, '--simpan' => true]);

        $this->assertSame(3, OvertimeRecord::count(), 'tidak ada catatan kedua');

        $r = OvertimeRecord::where('employee_id', $otomatis->id)->sole();
        $this->assertSame('confirmed', $r->status);
        $this->assertSame(240, (int) $r->payable_minutes, 'jam dari scan: 08:00 → 12:00');
        $this->assertNotNull($r->activated_at, 'kode terpakai otomatis');

        $this->assertSame(0, (int) OvertimeRecord::where('employee_id', $tidakDatang->id)->sole()->payable_minutes);

        $this->assertTrue($r->fresh()->activated_auto);
        $this->assertFalse(OvertimeRecord::where('employee_id', $sendiri->id)->sole()->activated_auto, 'yang memasukkan sendiri bukan otomatis');

        // Pemberitahuan LEWAT PORTAL, bukan WhatsApp, dan hanya untuk yang kodenya dipakai otomatis.
        Queue::assertNothingPushed();
        $pesan = Notification::sole();
        $this->assertSame($akun->id, $pesan->user_id);
        $this->assertStringContainsString('terpakai otomatis', $pesan->body);
        $this->assertStringContainsString('08:00–12:00', $pesan->body);
        $this->assertStringContainsString('×2', $pesan->body);

        // Halaman Lembur karyawan memuat kartu "tercatat otomatis".
        $this->actingAs($akun)->get(route('karyawan.lembur.index'))
            ->assertOk()
            ->assertSee('tercatat otomatis')
            ->assertSee('Anda tidak perlu memasukkannya')
            ->assertSee('08:00')
            ->assertSee('12:00')
            ->assertSee('×2');

        // Yang memasukkan kodenya sendiri tidak mendapat kartu itu.
        $akunSendiri = User::factory()->create(['role' => UserRole::Karyawan, 'employee_id' => $sendiri->id]);
        $this->actingAs($akunSendiri)->get(route('karyawan.lembur.index'))
            ->assertOk()
            ->assertDontSee('tercatat otomatis');

        Artisan::call('attendance:compute', ['--from' => self::TANGGAL, '--to' => self::TANGGAL]);
        $this->assertSame(240, (int) $r->fresh()->payable_minutes);
    }

    public function test_wajib_kode_mengembalikan_syarat_lama(): void
    {
        $aktif = $this->orang('Aktif', '11', null, ['08:05:00', '12:30:00']);
        $belum = $this->orang('Belum', '12', null, ['08:00:00', '12:00:00']);

        Artisan::call('lembur:kode-umum', ['tanggal' => self::TANGGAL]);

        app(OvertimeCodeService::class)->activate($aktif, OvertimeRequest::query()
            ->whereHas('request', fn ($q) => $q->where('employee_id', $aktif->id))
            ->value('secret_code'));

        $this->artisan('lembur:umum '.self::TANGGAL.' --wajib-kode')
            ->expectsOutputToContain('BELUM mengaktifkan kode')
            ->assertSuccessful();

        Artisan::call('lembur:umum', ['tanggal' => self::TANGGAL, '--wajib-kode' => true, '--simpan' => true]);

        $this->assertSame(265, (int) OvertimeRecord::where('employee_id', $aktif->id)->sole()->payable_minutes);
        $this->assertSame(0, (int) OvertimeRecord::where('employee_id', $belum->id)->sole()->payable_minutes);
    }

    public function test_tanpa_notif_tidak_mengirim_apa_apa(): void
    {
        $e = $this->orang('Tidak Minta', '12', null, ['08:00:00', '12:00:00']);
        User::factory()->create(['role' => UserRole::Karyawan, 'employee_id' => $e->id]);
        Artisan::call('lembur:kode-umum', ['tanggal' => self::TANGGAL]);

        Artisan::call('lembur:umum', ['tanggal' => self::TANGGAL, '--simpan' => true, '--tanpa-notif' => true]);

        $this->assertSame(240, (int) OvertimeRecord::sole()->payable_minutes);
        $this->assertSame(0, Notification::count(), 'tanpa pemberitahuan di portal');
    }

    /** General Cleaning tidak boleh lewat lembur:tugaskan, yang menyambung setelah jam pulang shift. */
    public function test_lembur_tugaskan_menolak_general_cleaning(): void
    {
        $this->orang('Pagi', '15', 'pagi', []);

        $this->assertSame(1, Artisan::call('lembur:tugaskan', [
            'pin' => '15', 'tanggal' => self::TANGGAL, '--keperluan' => 'general_cleaning', '--alasan' => 'uji',
        ]));
        $this->assertSame(0, OvertimeRecord::count());
    }

    /**
     * Roster boleh sudah diperbaiki jadi "pagi" untuk acaranya: yang menentukan
     * lembur adalah scan dan siapa yang berhak (--pin), BUKAN rosternya. Dulu
     * orang yang shift malamnya diubah ke pagi mendadak dianggap pagi biasa
     * tanpa lembur.
     */
    public function test_roster_pagi_tidak_menghilangkan_lembur_kalau_berhak(): void
    {
        $this->orang('Dulu Malam', '11', 'pagi', ['07:37:00']);   // rosternya sudah pagi, hanya scan masuk

        $this->artisan('lembur:umum '.self::TANGGAL.' --pin=11')
            ->expectsOutputToContain('1 orang')
            ->assertSuccessful();

        Artisan::call('lembur:umum', ['tanggal' => self::TANGGAL, '--pin' => ['11'], '--simpan' => true]);

        $r = OvertimeRecord::sole();
        $this->assertSame(323, (int) $r->payable_minutes, '07:37 → 13:00 (tanpa scan pulang)');
        $this->assertSame(2.0, (float) $r->multiplier);
    }
}
