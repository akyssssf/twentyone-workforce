<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\Attendance\AttendanceComputer;
use App\Services\Roster\RosterService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Admin kafe: punya akun, tetap digaji, tapi tidak menempel jari di mesin.
 *
 * Ini kasus yang sempat salah di produksi. Admin ditandai lewat
 * employment_status = 'admin', padahal penyaring absensi memakai kolom lain —
 * sehingga mereka tetap dihitung dan muncul sebagai Alpha setiap hari, lengkap
 * dengan potongan alphanya di payroll.
 */
class AttendanceTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function admin(): Employee
    {
        return Employee::factory()->tanpaAbsensi()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Admin Kafe',
            'default_shift_id' => Shift::where('code', 'pagi')->value('id'),
        ]);
    }

    protected function karyawan(): Employee
    {
        return Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Barista Kafe',
            'default_shift_id' => Shift::where('code', 'pagi')->value('id'),
        ]);
    }

    public function test_admin_tidak_pernah_jadi_alpha(): void
    {
        $admin = $this->admin();
        $karyawan = $this->karyawan();

        $hasil = app(AttendanceComputer::class)
            ->computeDate(Carbon::parse('2026-08-06', 'Asia/Jakarta'));

        // Hanya karyawan biasa yang dihitung. Tidak ada scan sama sekali dan
        // tidak ada roster nyata untuk tanggal ini, jadi (sementara) libur,
        // bukan alpha — lihat AttendanceComputer::resolveStatus().
        $this->assertSame(1, $hasil['computed']);
        $this->assertSame(1, $hasil['libur']);

        $this->assertSame(0, $admin->attendances()->count());
        $this->assertSame(1, $karyawan->attendances()->count());
    }

    public function test_admin_tidak_masuk_roster(): void
    {
        $admin = $this->admin();
        $karyawan = $this->karyawan();

        $service = app(RosterService::class);
        $roster = $service->findOrCreate(2026, 8);
        $service->generate($roster);

        $this->assertSame(0, $admin->rosterAssignments()->count());
        $this->assertSame(31, $karyawan->rosterAssignments()->count());
    }

    /** Admin tetap pegawai aktif — bukan resign, bukan nonaktif. */
    public function test_admin_tetap_terhitung_pegawai_aktif(): void
    {
        $admin = $this->admin();

        $this->assertTrue($admin->is_active);
        $this->assertSame('active', $admin->employment_status);
        $this->assertTrue(Employee::employed()->whereKey($admin->id)->exists());
        $this->assertFalse(Employee::tracked()->whereKey($admin->id)->exists());
    }

    /**
     * Nilai bawaan harus benar sejak model dibuat, bukan setelah di-refresh.
     *
     * Default di tingkat kolom saja tidak cukup: karyawan yang baru ditambahkan
     * lewat command atau impor akan memegang null pada pemanggilan pertama, dan
     * diam-diam luput dari absensi.
     */
    public function test_karyawan_baru_langsung_ikut_diabsen(): void
    {
        $employee = new Employee(['name' => 'Karyawan Baru']);

        $this->assertTrue($employee->tracks_attendance);
    }

    public function test_manajer_bisa_mematikan_absensi_dan_membersihkan_sisanya(): void
    {
        $karyawan = $this->karyawan();

        app(AttendanceComputer::class)->computeDate(Carbon::parse('2026-08-06', 'Asia/Jakarta'));
        $this->assertSame(1, $karyawan->attendances()->count());

        $manajer = \App\Models\User::factory()->create(['role' => \App\Enums\UserRole::Admin]);

        $this->actingAs($manajer)
            ->post(route('manajer.karyawan.absensi', $karyawan), [])
            ->assertRedirect();

        $karyawan->refresh();

        $this->assertFalse($karyawan->tracks_attendance);

        // Catatan yang terlanjur dibuat ikut dibersihkan, kalau tidak dia tetap
        // muncul sebagai Alpha di laporan bulan berjalan.
        $this->assertSame(0, $karyawan->attendances()->count());
    }
}
