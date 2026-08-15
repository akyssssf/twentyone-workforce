<?php

namespace Tests\Feature;

use App\Enums\OvertimeOccasion;
use App\Enums\UserRole;
use App\Models\AttendanceLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\Shift;
use App\Models\User;
use App\Services\Attendance\OvertimeResolver;
use App\Services\Requests\OvertimeCodeService;
use App\Services\Requests\RequestService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * Lembur lewat kode rahasia.
 *
 * Approval menjawab "lembur ini disetujui"; kode menjawab "yang mengerjakannya
 * benar orang yang ditunjuk". Tanpa kode, di malam sibuk siapa pun yang
 * kebetulan masih di tempat bisa mengaku sebagai yang ditugaskan.
 */
class OvertimeCodeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Employee $rifqi;

    protected Employee $rekan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-10 18:00:00', 'Asia/Jakarta'));

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);

        // Shift pagi, bukan malam: lembur berarti menyambung shift sampai
        // kafe tutup, dan setelah shift malam tidak ada lagi yang bisa
        // disambung.
        $shift = Shift::where('code', 'pagi')->first();

        $this->rifqi = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Rifqi',
            'default_shift_id' => $shift->id,
        ]);

        $this->rekan = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Rekan',
            'default_shift_id' => $shift->id,
        ]);

        // Lembur menyambung shift yang dijadwalkan, jadi rosternya wajib ada.
        $this->jadwalkan($this->rifqi, $shift);
        $this->jadwalkan($this->rekan, $shift);
    }

    protected function jadwalkan(Employee $employee, Shift $shift, ?Carbon $tanggal = null): void
    {
        app(\App\Services\Roster\RosterService::class)->assign(
            app(\App\Services\Roster\RosterService::class)->findOrCreate(2026, 8),
            $employee,
            $tanggal ?? today(),
            $shift->id,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function tugaskan(?Employee $untuk = null): OvertimeRecord
    {
        $this->actingAs($this->admin);

        $service = app(RequestService::class);

        // Ditunjuk manajer sudah otomatis Approved sejak dibuat — tidak ada
        // approve() terpisah lagi untuk jalur ini.
        // Jam tidak lagi diketik: diturunkan dari shift orangnya hari itu.
        $service->submitOvertime($untuk ?? $this->rifqi, [
            'work_date' => today()->toDateString(),
            'reason' => 'Persiapan katering pesanan besar',
            'substitute_employee_id' => $this->rekan->id,
        ], 'manager');

        return OvertimeRecord::where('employee_id', ($untuk ?? $this->rifqi)->id)->latest('id')->first();
    }

    public function test_penugasan_menghasilkan_kode_unik(): void
    {
        // Orang ketiga, karena pengganti tidak boleh diri sendiri.
        $lain = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Orang Ketiga',
        ]);
        $this->jadwalkan($lain, Shift::where('code', 'pagi')->first());

        $satu = $this->tugaskan();
        $dua = $this->tugaskan($lain);

        $kodeSatu = $satu->overtimeRequest->secret_code;
        $kodeDua = $dua->overtimeRequest->secret_code;

        $this->assertNotEmpty($kodeSatu);
        $this->assertNotSame($kodeSatu, $kodeDua);

        // Huruf yang rancu saat dibacakan lewat telepon tidak boleh muncul.
        $this->assertDoesNotMatchRegularExpression('/[01OIS58B]/', $kodeSatu);
    }

    public function test_kode_mengaktifkan_lembur(): void
    {
        $record = $this->tugaskan();
        $kode = $record->overtimeRequest->secret_code;

        $this->assertFalse($record->isActivated());

        // Huruf kecil dan spasi tetap diterima: kode sering disalin dari chat.
        $hasil = app(OvertimeCodeService::class)->activate($this->rifqi, ' ' . strtolower($kode) . ' ');

        $this->assertTrue($hasil->isActivated());
    }

    /**
     * Lembur yang manajer sendiri yang menunjuk sudah otomatis Approved
     * sejak dibuat — tidak perlu klik "setujui" terpisah untuk keputusan
     * yang baru saja diambil manajer itu sendiri.
     */
    public function test_lembur_tunjukan_manajer_langsung_approved(): void
    {
        $record = $this->tugaskan();

        $this->assertSame('approved', $record->overtimeRequest->request->status->value);
        $this->assertNotNull($record->overtimeRequest->request->decided_at);
    }

    // ------------------------------------------------- hitung otomatis

    protected function scan(Employee $employee, string $waktu): void
    {
        $at = Carbon::parse($waktu, 'Asia/Jakarta');

        AttendanceLog::create([
            'cloud_id' => 'UJI',
            'employee_id' => $employee->id,
            'pin' => $employee->pin_device,
            'scanned_at' => $at,
            'scan_minute' => $at->copy()->startOfMinute(),
            'source' => 'webhook',
        ]);
    }

    /** Shift pagi seeder: 09:00-17:00. Kafe tutup saat shift malam kelar, 01:00. */
    protected function hitungLembur(): int
    {
        $shift = Shift::where('code', 'pagi')->first();

        return app(OvertimeResolver::class)->minutesFor(
            $this->rifqi,
            Carbon::parse('2026-08-10', 'Asia/Jakarta'),
            $shift,
            Carbon::parse('2026-08-10 17:00:00', 'Asia/Jakarta'),
        );
    }

    public function test_menit_lembur_dihitung_dari_scan_terakhir(): void
    {
        $record = $this->tugaskan();
        app(OvertimeCodeService::class)->activate($this->rifqi, $record->overtimeRequest->secret_code);

        $this->scan($this->rifqi, '2026-08-10 19:30:00');

        // Hari sudah lewat jam tutup, jadi hasilnya boleh disimpulkan.
        Carbon::setTestNow(Carbon::parse('2026-08-11 02:00:00', 'Asia/Jakarta'));

        $this->assertSame(150, $this->hitungLembur());
    }

    /**
     * Lupa scan pulang membayar paling banyak — pilihan sadar, supaya yang
     * lembur tidak dirugikan karena lupa menempel jari.
     */
    public function test_tanpa_scan_pulang_dihitung_penuh_sampai_kafe_tutup(): void
    {
        $record = $this->tugaskan();
        app(OvertimeCodeService::class)->activate($this->rifqi, $record->overtimeRequest->secret_code);

        Carbon::setTestNow(Carbon::parse('2026-08-11 02:00:00', 'Asia/Jakarta'));

        // 17:00 sampai 01:00 keesokan harinya.
        $this->assertSame(480, $this->hitungLembur());
    }

    public function test_hari_yang_belum_tutup_belum_ikut_terbayar(): void
    {
        $record = $this->tugaskan();
        app(OvertimeCodeService::class)->activate($this->rifqi, $record->overtimeRequest->secret_code);

        $this->scan($this->rifqi, '2026-08-10 19:30:00');

        // Masih jam 20:00, orangnya mungkin belum pulang.
        Carbon::setTestNow(Carbon::parse('2026-08-10 20:00:00', 'Asia/Jakarta'));

        $this->assertSame(0, $this->hitungLembur());
        $this->assertSame(150, OvertimeRecord::find($record->id)->actual_minutes);
    }

    /** Tanpa aktivasi kode, tidak ada bukti siapa yang mengerjakannya. */
    public function test_tanpa_aktivasi_kode_tidak_dihitung(): void
    {
        $this->tugaskan();
        $this->scan($this->rifqi, '2026-08-10 19:30:00');

        Carbon::setTestNow(Carbon::parse('2026-08-11 02:00:00', 'Asia/Jakarta'));

        $this->assertSame(0, $this->hitungLembur());
    }

    public function test_koreksi_manajer_tidak_ditimpa_hitungan_ulang(): void
    {
        $record = $this->tugaskan();
        app(OvertimeCodeService::class)->activate($this->rifqi, $record->overtimeRequest->secret_code);

        $this->scan($this->rifqi, '2026-08-10 19:30:00');
        Carbon::setTestNow(Carbon::parse('2026-08-11 02:00:00', 'Asia/Jakarta'));

        $record->update([
            'actual_minutes' => 90,
            'payable_minutes' => 90,
            'status' => 'confirmed',
            'confirmed_by' => $this->admin->id,
            'confirmed_at' => now(),
            'note' => 'Pulang lebih awal, disaksikan langsung.',
        ]);

        $this->assertSame(90, $this->hitungLembur());
        $this->assertSame(90, OvertimeRecord::find($record->id)->actual_minutes);
    }

    // ------------------------------------------------- keperluan lembur

    public function test_lembur_acara_tidak_perlu_pengganti(): void
    {
        $this->actingAs($this->admin);

        app(RequestService::class)->submitOvertime($this->rifqi, [
            'work_date' => today()->toDateString(),
            'occasion' => OvertimeOccasion::LiveMusic->value,
            'reason' => 'Tambahan tenaga untuk live music',
        ], 'manager');

        $record = OvertimeRecord::where('employee_id', $this->rifqi->id)->latest('id')->first();

        $this->assertSame(OvertimeOccasion::LiveMusic, $record->overtimeRequest->occasion);
        $this->assertNull($record->overtimeRequest->request->substitute_employee_id);
    }

    public function test_lembur_penggantian_tetap_wajib_menunjuk_pengganti(): void
    {
        $this->actingAs($this->admin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pengganti wajib dipilih');

        app(RequestService::class)->submitOvertime($this->rifqi, [
            'work_date' => today()->toDateString(),
            'occasion' => OvertimeOccasion::Pengganti->value,
            'reason' => 'Menutup posisi rekan yang izin',
        ], 'manager');
    }

    /** Baris lama dibuat sebelum kolom keperluan ada — semuanya penggantian. */
    public function test_tanpa_keperluan_dianggap_penggantian(): void
    {
        $record = $this->tugaskan();

        $this->assertSame(OvertimeOccasion::Pengganti, $record->overtimeRequest->occasion);
    }

    // --------------------------------------------------- lewat form admin

    /**
     * Penugasan massal lewat form pernah cuma jadi untuk orang PERTAMA:
     * controller memanggil approve() sekali lagi padahal jalur manajer sudah
     * disetujui sejak dibuat, lemparannya menghentikan perulangan, dan sisanya
     * diam-diam tidak pernah dibuat.
     */
    public function test_form_admin_menugaskan_semua_orang_yang_dipilih(): void
    {
        $ketiga = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Orang Ketiga',
        ]);
        $this->jadwalkan($ketiga, Shift::where('code', 'pagi')->first());

        $response = $this->actingAs($this->admin)->post(route('manajer.lembur.store'), [
            'employee_ids' => [$this->rifqi->id, $ketiga->id],
            'occasion' => OvertimeOccasion::Pengganti->value,
            'substitute_employee_id' => $this->rekan->id,
            'work_date' => today()->toDateString(),
            'reason' => 'Persiapan katering pesanan besar',
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertSame(1, OvertimeRecord::where('employee_id', $this->rifqi->id)->count());
        $this->assertSame(1, OvertimeRecord::where('employee_id', $ketiga->id)->count());
    }

    public function test_form_admin_menolak_penggantian_tanpa_pengganti(): void
    {
        $response = $this->actingAs($this->admin)->post(route('manajer.lembur.store'), [
            'employee_ids' => [$this->rifqi->id],
            'occasion' => OvertimeOccasion::Pengganti->value,
            'work_date' => today()->toDateString(),
            'reason' => 'Menutup posisi rekan yang izin',
        ]);

        $response->assertSessionHasErrors('substitute_employee_id');
        $this->assertSame(0, OvertimeRecord::where('employee_id', $this->rifqi->id)->count());
    }

    public function test_form_admin_menerima_acara_tanpa_pengganti(): void
    {
        $response = $this->actingAs($this->admin)->post(route('manajer.lembur.store'), [
            'employee_ids' => [$this->rifqi->id],
            'occasion' => OvertimeOccasion::Nobar->value,
            'work_date' => today()->toDateString(),
            'reason' => 'Nobar final, tamu membludak',
        ]);

        $response->assertSessionHasNoErrors();

        $record = OvertimeRecord::where('employee_id', $this->rifqi->id)->latest('id')->first();
        $this->assertSame(OvertimeOccasion::Nobar, $record->overtimeRequest->occasion);
    }

    public function test_shift_malam_tidak_bisa_ditugaskan_lembur(): void
    {
        $malam = Shift::where('code', 'malam')->first();
        $this->jadwalkan($this->rifqi, $malam);

        $this->actingAs($this->admin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('kafe tutup');

        app(RequestService::class)->submitOvertime($this->rifqi, [
            'work_date' => today()->toDateString(),
            'reason' => 'Bersih-bersih setelah tutup',
            'substitute_employee_id' => $this->rekan->id,
        ], 'manager');
    }

    public function test_tanpa_jadwal_shift_tidak_bisa_ditugaskan_lembur(): void
    {
        $tanpaJadwal = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Belum Dijadwalkan',
        ]);

        $this->actingAs($this->admin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak punya jadwal shift');

        app(RequestService::class)->submitOvertime($tanpaJadwal, [
            'work_date' => today()->toDateString(),
            'reason' => 'Bantu stok opname',
            'substitute_employee_id' => $this->rekan->id,
        ], 'manager');
    }

    public function test_kode_orang_lain_ditolak(): void
    {
        $record = $this->tugaskan();
        $kode = $record->overtimeRequest->secret_code;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bukan untuk Anda');

        app(OvertimeCodeService::class)->activate($this->rekan, $kode);
    }

    public function test_kode_tidak_bisa_dipakai_dua_kali(): void
    {
        $record = $this->tugaskan();
        $kode = $record->overtimeRequest->secret_code;

        app(OvertimeCodeService::class)->activate($this->rifqi, $kode);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sudah diaktifkan');

        app(OvertimeCodeService::class)->activate($this->rifqi, $kode);
    }

    /** Kode lama tidak boleh dipakai berminggu-minggu kemudian. */
    public function test_kode_kedaluwarsa_di_luar_tanggal_lembur(): void
    {
        $record = $this->tugaskan();
        $kode = $record->overtimeRequest->secret_code;

        Carbon::setTestNow(Carbon::parse('2026-08-20 18:00:00', 'Asia/Jakarta'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hanya berlaku');

        app(OvertimeCodeService::class)->activate($this->rifqi, $kode);
    }

    public function test_kode_ngawur_ditolak(): void
    {
        $this->tugaskan();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak dikenali');

        app(OvertimeCodeService::class)->activate($this->rifqi, 'XXXXXX');
    }

    public function test_halaman_lembur_karyawan_menampilkan_kode_yang_menunggu(): void
    {
        $this->tugaskan();

        $akun = User::factory()->create([
            'role' => UserRole::Karyawan,
            'employee_id' => $this->rifqi->id,
        ]);

        $this->actingAs($akun)
            ->get(route('karyawan.lembur.index'))
            ->assertOk()
            ->assertSee('Mulai Lembur')
            ->assertSee('Belum diaktifkan');
    }

    public function test_aktivasi_lewat_halaman_lembur(): void
    {
        $record = $this->tugaskan();

        $akun = User::factory()->create([
            'role' => UserRole::Karyawan,
            'employee_id' => $this->rifqi->id,
        ]);

        $this->actingAs($akun)
            ->post(route('karyawan.lembur.aktivasi'), ['kode' => $record->overtimeRequest->secret_code])
            ->assertRedirect();

        $this->assertTrue($record->fresh()->isActivated());
    }
}
