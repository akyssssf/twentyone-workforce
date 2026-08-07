<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Enums\UserRole;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Request as PengajuanModel;
use App\Models\Shift;
use App\Models\User;
use App\Services\Requests\RequestService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Admin sebagai pusat koordinasi pengganti.
 *
 * Banyak pengganti lebih gampang dihubungi langsung lewat telepon/WA
 * pribadi daripada disuruh buka aplikasi cuma untuk klik "bersedia". Dua
 * hal yang dijaga di sini: admin selalu diberi tahu begitu ada pengajuan
 * yang butuh konfirmasi pengganti, dan admin bisa menandai pengganti
 * bersedia sendiri tanpa penggantinya login sama sekali.
 */
class RequestSubstituteAdminTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Employee $pengaju;

    protected Employee $pengganti;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-10 18:00:00', 'Asia/Jakarta'));

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);

        $shift = Shift::where('code', 'malam')->first();

        $this->pengaju = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Rifqi',
            'default_shift_id' => $shift->id,
        ]);

        $this->pengganti = Employee::factory()->create([
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

    public function test_admin_diberi_tahu_begitu_pengajuan_butuh_konfirmasi_pengganti(): void
    {
        Queue::fake();

        $this->actingAs($this->admin);

        // Lembur initiated_by employee tetap dua tahap (pengganti dulu),
        // beda dari yang ditunjuk manajer yang sekarang langsung Approved.
        app(RequestService::class)->submitOvertime($this->pengaju, [
            'work_date' => today()->toDateString(),
            'planned_start' => '01:00',
            'planned_end' => '03:00',
            'reason' => 'Persiapan katering',
            'substitute_employee_id' => $this->pengganti->id,
        ], 'employee');

        Queue::assertPushed(SendWhatsAppMessage::class, function (SendWhatsAppMessage $job) {
            return str_contains($job->pesan, 'Menunggu konfirmasi pengganti')
                || str_contains($job->pesan, 'menunjuk Rekan sebagai pengganti');
        });
    }

    public function test_admin_bisa_menandai_pengganti_setuju_tanpa_pengganti_login(): void
    {
        $pengajuan = PengajuanModel::create([
            'code' => 'REQ-TEST-0001',
            'branch_id' => Branch::current()->id,
            'type' => RequestType::Leave,
            'employee_id' => $this->pengaju->id,
            'substitute_employee_id' => $this->pengganti->id,
            'status' => RequestStatus::PendingPeer,
            'submitted_at' => now(),
        ]);

        $hasil = app(RequestService::class)->confirmSubstituteByAdmin($pengajuan, $this->admin, 'Sudah telepon, dia bersedia.');

        $this->assertSame(RequestStatus::PendingManager, $hasil->status);
        $this->assertNotNull($hasil->substitute_accepted_at);
        $this->assertSame('Sudah telepon, dia bersedia.', $hasil->substitute_note);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'request.substitute_confirmed_by_admin',
        ]);
    }

    public function test_tidak_bisa_konfirmasi_pengganti_kalau_bukan_pending_peer(): void
    {
        $pengajuan = PengajuanModel::create([
            'code' => 'REQ-TEST-0002',
            'branch_id' => Branch::current()->id,
            'type' => RequestType::Leave,
            'employee_id' => $this->pengaju->id,
            'substitute_employee_id' => $this->pengganti->id,
            'status' => RequestStatus::PendingManager,
            'submitted_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);

        app(RequestService::class)->confirmSubstituteByAdmin($pengajuan, $this->admin);
    }

    public function test_tombol_konfirmasi_pengganti_muncul_di_halaman_pengajuan(): void
    {
        $pengajuan = PengajuanModel::create([
            'code' => 'REQ-TEST-0003',
            'branch_id' => Branch::current()->id,
            'type' => RequestType::Leave,
            'employee_id' => $this->pengaju->id,
            'substitute_employee_id' => $this->pengganti->id,
            'status' => RequestStatus::PendingPeer,
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('manajer.pengajuan.show', $pengajuan))
            ->assertOk()
            ->assertSee('Tandai pengganti sudah setuju');
    }
}
