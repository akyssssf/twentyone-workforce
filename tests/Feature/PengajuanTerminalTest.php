<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Request as Pengajuan;
use App\Models\User;
use App\Services\Requests\RequestService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PengajuanTerminalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-10 09:00:00', 'Asia/Jakarta'));
        User::factory()->create(['role' => UserRole::Admin]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function ajukanCuti(string $pengaju, string $pengganti, string $tanggal = '2026-08-15'): Pengajuan
    {
        $a = Employee::factory()->create(['branch_id' => Branch::current()->id, 'name' => $pengaju]);
        $b = Employee::factory()->create(['branch_id' => Branch::current()->id, 'name' => $pengganti]);

        return app(RequestService::class)->submitLeave($a, [
            'leave_type_id' => LeaveType::where('code', 'sakit')->firstOrFail()->id,
            'start_date' => $tanggal,
            'end_date' => $tanggal,
            'reason' => 'Demam tinggi',
            'substitute_employee_id' => $b->id,
        ]);
    }

    public function test_setujui_dengan_konfirmasi_pengganti_dan_kode_ekor(): void
    {
        $p = $this->ajukanCuti('Dava', 'Nuryati');
        $this->assertSame(RequestStatus::PendingPeer, $p->status);

        $ekor = substr($p->code, -7);
        $this->assertSame(0, Artisan::call('pengajuan:setujui', ['kode' => [$ekor], '--konfirmasi-pengganti' => true]), Artisan::output());

        $p = $p->fresh();
        $this->assertSame(RequestStatus::Approved, $p->status);
        $this->assertNotNull($p->decided_by);
    }

    public function test_setujui_tanpa_konfirmasi_pengganti_ditolak_dengan_pesan(): void
    {
        $p = $this->ajukanCuti('Dava', 'Nuryati');

        $this->assertSame(1, Artisan::call('pengajuan:setujui', ['kode' => [$p->code]]));
        $this->assertSame(RequestStatus::PendingPeer, $p->fresh()->status);
        $this->assertStringContainsString('tidak sedang menunggu persetujuan manager', Artisan::output());
    }

    public function test_tolak_butuh_alasan_dan_mengembalikan_saldo(): void
    {
        $p = $this->ajukanCuti('Dava', 'Nuryati');

        $this->assertSame(1, Artisan::call('pengajuan:tolak', ['kode' => [$p->code]]));
        $this->assertSame(RequestStatus::PendingPeer, $p->fresh()->status);

        $this->assertSame(0, Artisan::call('pengajuan:tolak', ['kode' => [$p->code], '--alasan' => 'Sudah ada yang cuti hari itu']));
        $p = $p->fresh();
        $this->assertSame(RequestStatus::Rejected, $p->status);
        $this->assertSame('Sudah ada yang cuti hari itu', $p->decision_note);
    }

    public function test_beberapa_kode_satu_gagal_yang_lain_tetap_diproses(): void
    {
        $a = $this->ajukanCuti('Dava', 'Nuryati', '2026-08-15');
        $b = $this->ajukanCuti('Farrel', 'Zahra', '2026-08-16');

        $this->assertSame(1, Artisan::call('pengajuan:tolak', [
            'kode' => [$a->code, 'REQ-TIDAK-ADA', $b->code],
            '--alasan' => 'Jadwal penuh hari itu',
        ]));

        $this->assertSame(RequestStatus::Rejected, $a->fresh()->status);
        $this->assertSame(RequestStatus::Rejected, $b->fresh()->status);
        $this->assertStringContainsString('tidak ditemukan', Artisan::output());
    }
}
