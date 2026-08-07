<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------- auth

    public function test_tamu_diarahkan_ke_halaman_masuk(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        
        // Akar diarahkan ke /beranda, yang membagi manajer ke dashboard dan
        // karyawan ke portalnya masing-masing.
        $this->get('/')->assertRedirect('/beranda');
    }

    public function test_bisa_masuk_dengan_kredensial_benar(): void
    {
        $user = User::factory()->create(['username' => 'admin']);

        $this->post(route('login.store'), [
            'username' => 'admin',
            'password' => 'password',
        ])->assertRedirect(route('beranda'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_kredensial_salah_ditolak(): void
    {
        User::factory()->create(['username' => 'admin']);

        $this->post(route('login.store'), [
            'username' => 'admin',
            'password' => 'salah',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    /**
     * Pesan galat tidak boleh membedakan "nama tidak ada" dari "sandi salah",
     * karena bedanya bisa dipakai menebak siapa saja yang punya akun.
     */
    public function test_pesan_galat_tidak_membocorkan_akun_terdaftar(): void
    {
        User::factory()->create(['username' => 'ada']);

        $adaTapiSalah = $this->post(route('login.store'), [
            'username' => 'ada', 'password' => 'salah',
        ])->assertSessionHasErrors('username');

        $this->flushSession();

        $tidakAda = $this->post(route('login.store'), [
            'username' => 'tidakada', 'password' => 'salah',
        ])->assertSessionHasErrors('username');

        $this->assertSame(
            $adaTapiSalah->getSession()->get('errors')->first('username'),
            $tidakAda->getSession()->get('errors')->first('username'),
        );
    }

    /** Huruf besar tidak boleh bikin gagal masuk: ponsel mengapitalkan sendiri. */
    public function test_nama_panggilan_tidak_peka_huruf_besar(): void
    {
        $user = User::factory()->create(['username' => 'dian']);

        $this->post(route('login.store'), [
            'username' => 'Dian', 'password' => 'password',
        ])->assertRedirect(route('beranda'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_percobaan_masuk_dibatasi(): void
    {
        User::factory()->create(['username' => 'admin']);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.store'), ['username' => 'admin', 'password' => 'salah']);
            $this->flushSession();
        }

        $this->post(route('login.store'), ['username' => 'admin', 'password' => 'password'])
            ->assertSessionHasErrors('username');

        // Sandi benar pun ditolak selama masih dalam masa tunggu.
        $this->assertGuest();
    }

    public function test_akun_nonaktif_tidak_bisa_masuk(): void
    {
        User::factory()->nonaktif()->create(['username' => 'resign']);

        $this->post(route('login.store'), [
            'username' => 'resign', 'password' => 'password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    /**
     * Menonaktifkan akun harus langsung berlaku, tidak menunggu dia logout.
     */
    public function test_sesi_yang_sedang_hidup_diputus_saat_akun_dinonaktifkan(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->update(['is_active' => false]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_bisa_keluar(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_peran_tersimpan_sebagai_enum(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue($admin->isAdmin());
        $this->assertSame('Admin', $admin->role->label());
    }

    // -------------------------------------------------------------- dashboard

    protected function siapkanData(): array
    {
        $shift = Shift::factory()->create(['name' => 'Shift 1']);
        $malam = Shift::factory()->malam()->create(['name' => 'Shift 2']);

        $budi = Employee::factory()->create(['pin_device' => '1', 'name' => 'Budi', 'default_shift_id' => $shift->id]);
        $sari = Employee::factory()->create(['pin_device' => '2', 'name' => 'Sari', 'default_shift_id' => $malam->id]);

        $this->scan('1', '2026-08-06 09:07:00');
        $this->scan('2', '2026-08-06 17:00:00');
        $this->scan('2', '2026-08-07 00:30:00');

        app(\App\Services\Attendance\AttendanceComputer::class)
            ->computeDate(Carbon::parse('2026-08-06', 'Asia/Jakarta'));

        return [$budi, $sari];
    }

    protected function scan(string $pin, string $waktu, ?string $photo = null): AttendanceLog
    {
        $at = Carbon::parse($waktu, 'Asia/Jakarta');

        return AttendanceLog::create([
            'cloud_id' => 'GQ5179086',
            'pin' => $pin,
            'scanned_at' => $at,
            'scan_minute' => $at->copy()->startOfMinute(),
            'verify_mode' => 4,
            'status_scan' => 0,
            'photo_url' => $photo,
            'source' => 'webhook',
        ]);
    }

    public function test_dashboard_menampilkan_rekap_dan_scan(): void
    {
        $this->siapkanData();

        $this->actingAs(User::factory()->create())
            ->get('/dashboard?tanggal=2026-08-06')
            ->assertOk()
            ->assertSee('Budi')
            ->assertSee('Sari')
            // Keterlambatan tampil sebagai menit, bukan sebagai status dan
            // bukan sebagai rupiah — nominalnya urusan slip gaji.
            ->assertSee('Terlambat');
    }

    public function test_dashboard_menandai_pin_yang_belum_terdaftar(): void
    {
        $this->scan('99', '2026-08-06 10:00:00');

        $this->actingAs(User::factory()->create())
            ->get('/dashboard?tanggal=2026-08-06')
            ->assertOk()
            ->assertSee('PIN belum terdaftar')
            ->assertSee('99');
    }

    /**
     * Scan pulang Shift 2 jatuh di tanggal berikutnya, tapi tetap harus
     * kelihatan di halaman tanggal kerjanya.
     */
    public function test_scan_pulang_shift_malam_ikut_tampil(): void
    {
        $this->siapkanData();

        $this->actingAs(User::factory()->create())
            ->get('/dashboard?tanggal=2026-08-06')
            ->assertOk()
            ->assertSee('07 Aug 00:30:00')
            ->assertSee('(+1 hari)');
    }

    public function test_foto_scan_ditampilkan_kalau_ada(): void
    {
        // Mesin Vivo W-2421M memotret wajah tiap scan.
        $this->scan('1', '2026-08-06 09:00:00', 'https://s3.example.com/foto/abc.jpg');

        $this->actingAs(User::factory()->create())
            ->get('/dashboard?tanggal=2026-08-06')
            ->assertOk()
            ->assertSee('https://s3.example.com/foto/abc.jpg');
    }

    public function test_tanggal_ngawur_di_url_tidak_bikin_error(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard?tanggal=bukan-tanggal')
            ->assertOk()
            // Kembali ke hari ini alih-alih meledak.
            ->assertSee(Carbon::today('Asia/Jakarta')->translatedFormat('d F Y'));
    }

    public function test_dashboard_memberi_petunjuk_kalau_rekap_belum_dihitung(): void
    {
        Employee::factory()->create(['default_shift_id' => Shift::factory()->create()->id]);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard?tanggal=2026-08-06')
            ->assertOk()
            ->assertSee('attendance:compute');
    }

    public function test_manajer_juga_bisa_membuka_dashboard(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_ringkasan_menjumlahkan_status_dengan_benar(): void
    {
        [$budi] = $this->siapkanData();

        // Karyawan ketiga tanpa scan sama sekali.
        Employee::factory()->create([
            'pin_device' => '3', 'name' => 'Andi',
            'default_shift_id' => Shift::where('name', 'Shift 1')->first()->id,
        ]);

        app(\App\Services\Attendance\AttendanceComputer::class)
            ->computeDate(Carbon::parse('2026-08-06', 'Asia/Jakarta'));

        $response = $this->actingAs(User::factory()->create())->get('/dashboard?tanggal=2026-08-06');

        $ringkasan = $response->viewData('ringkasan');

        $this->assertSame(3, $ringkasan['karyawan']);

        // Yang telat tetap terhitung hadir: dua dimensi berbeda, bukan dua
        // status yang saling meniadakan.
        $this->assertSame(2, $ringkasan['hadir']);
        $this->assertSame(1, $ringkasan['terlambat']);
        $this->assertSame(1, $ringkasan['alpha']);
    }
}
