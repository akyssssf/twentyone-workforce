<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\NotificationDelivery;
use App\Models\Shift;
use App\Models\User;
use App\Services\Requests\RequestService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pengiriman WhatsApp.
 *
 * Yang dijaga di sini bukan "pesannya sampai" — itu urusan gateway — tapi tiga
 * hal yang jadi tanggung jawab aplikasi: pesan dikirim lewat antrean, kegagalan
 * meninggalkan jejak, dan kegagalan tidak pernah membatalkan approval.
 */
class WhatsAppNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Employee $karyawan;

    protected Employee $pengganti;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-10 18:00:00', 'Asia/Jakarta'));

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $shift = Shift::where('code', 'malam')->first();

        $this->karyawan = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Rifqi',
            'phone' => '081234567890',
            'default_shift_id' => $shift->id,
        ]);

        $this->pengganti = Employee::factory()->create([
            'branch_id' => Branch::current()->id,
            'name' => 'Rekan',
            'phone' => '081298765432',
            'default_shift_id' => $shift->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function tugaskanLembur(): void
    {
        $this->actingAs($this->admin);

        $service = app(RequestService::class);

        $pengajuan = $service->submitOvertime($this->karyawan, [
            'work_date' => today()->toDateString(),
            'planned_start' => '01:00',
            'planned_end' => '03:00',
            'reason' => 'Persiapan katering pesanan besar',
            'substitute_employee_id' => $this->pengganti->id,
        ], 'manager');

        $service->approve($pengajuan->fresh(), $this->admin, 'Disetujui');
    }

    public function test_kode_lembur_dikirim_lewat_antrean(): void
    {
        Queue::fake();

        $this->tugaskanLembur();

        // Lewat antrean, bukan di dalam permintaan HTTP: gateway bisa
        // menggantung belasan detik dan admin tidak boleh menunggu.
        Queue::assertPushed(SendWhatsAppMessage::class, function (SendWhatsAppMessage $job) {
            return $job->nomor === '6281234567890'
                && str_contains($job->pesan, 'KODE LEMBUR:');
        });
    }

    public function test_pengiriman_dicatat_di_outbox(): void
    {
        Queue::fake();

        $this->tugaskanLembur();

        $this->assertDatabaseHas('notification_deliveries', [
            'channel' => 'whatsapp',
            'status' => 'pending',
        ]);
    }

    /**
     * Nomor kosong bukan kegagalan diam-diam.
     *
     * Justru inilah yang menjelaskan kenapa seseorang tidak pernah menerima
     * kode lemburnya, dan jadi daftar kerja untuk melengkapi nomor karyawan.
     */
    public function test_nomor_kosong_tercatat_sebagai_gagal(): void
    {
        Queue::fake();

        $this->karyawan->update(['phone' => null]);

        $this->tugaskanLembur();

        $gagal = NotificationDelivery::where('channel', 'whatsapp')
            ->where('status', 'failed')
            ->first();

        $this->assertNotNull($gagal);
        $this->assertStringContainsString('belum diisi', $gagal->error);

        // Pemberitahuan ke nomor admin tetap terkirim — yang tidak terkirim
        // hanya yang menuju karyawan tanpa nomor.
        Queue::assertNotPushed(
            SendWhatsAppMessage::class,
            fn (SendWhatsAppMessage $job) => str_contains($job->pesan, 'KODE LEMBUR:'),
        );
    }

    /** Approval tetap tercatat walaupun pemberitahuannya gagal. */
    public function test_kegagalan_kirim_tidak_membatalkan_approval(): void
    {
        Queue::fake();

        $this->karyawan->update(['phone' => null]);

        $this->tugaskanLembur();

        $this->assertDatabaseHas('overtime_records', [
            'employee_id' => $this->karyawan->id,
            'status' => 'pending_confirmation',
        ]);
    }

    public function test_nomor_diseragamkan_sebelum_dikirim(): void
    {
        Queue::fake();

        // Ditulis dengan gaya lain, hasil kirimnya harus tetap sama.
        $this->karyawan->update(['phone' => '+62 812-3456-7890']);

        $this->tugaskanLembur();

        Queue::assertPushed(SendWhatsAppMessage::class, fn ($job) => $job->nomor === '6281234567890');
    }

    /** Driver bawaan tidak boleh mengirim apa pun ke nomor sungguhan. */
    public function test_driver_bawaan_adalah_log(): void
    {
        $this->assertSame('log', config('whatsapp.driver'));
        $this->assertFalse(app(\App\Services\Notifications\WhatsApp\WhatsAppManager::class)->aktif());
    }
}
