<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\Shift;
use App\Models\User;
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

        $shift = Shift::where('code', 'malam')->first();

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
        $service->submitOvertime($untuk ?? $this->rifqi, [
            'work_date' => today()->toDateString(),
            'planned_start' => '01:00',
            'planned_end' => '03:00',
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
