<?php

namespace App\Services\Requests;

use App\Enums\AttendanceStatus;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Models\AttendanceAdjustment;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedger;
use App\Models\OvertimeRecord;
use App\Models\OvertimeRequest;
use App\Models\Request;
use App\Models\RosterAssignment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\Notifier;
use App\Services\Payroll\PayrollLockGuard;
use App\Services\Roster\RosterService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Satu mesin state untuk keempat jenis pengajuan.
 *
 * Sejak kebijakan baru, SEMUA pengajuan menempuh dua tahap persetujuan:
 * pengganti dulu, baru manajer. Alasannya operasional, bukan birokratis —
 * pengajuan yang disetujui tanpa ada yang menutup shift-nya baru ketahuan pada
 * hari H, saat sudah tidak ada waktu mencari orang.
 *
 * Alur approval, audit, notifikasi, dan kedaluwarsa hidup di sini — satu jalur,
 * bukan enam salinan. Yang khas per jenis hanya dua hal: validasi sebelum
 * diajukan, dan efek setelah disetujui. Keduanya dipisah ke method sendiri
 * supaya jelas mana yang umum dan mana yang khusus.
 */
class RequestService
{
    public function __construct(
        protected RosterService $roster,
        protected PayrollLockGuard $lockGuard,
        protected Notifier $notifier,
        protected LeaveService $leave,
    ) {}

    // ------------------------------------------------------------ pengganti

    /**
     * Pastikan pengganti sah sebelum pengajuan dibuat.
     *
     * @throws RuntimeException
     */
    protected function assertSubstitute(Employee $pengaju, ?int $substituteId): Employee
    {
        if ($substituteId === null) {
            throw new RuntimeException('Pengganti wajib dipilih. Sebutkan siapa yang menutup posisi Anda.');
        }

        if ($substituteId === $pengaju->id) {
            throw new RuntimeException('Pengganti tidak boleh diri sendiri.');
        }

        $pengganti = Employee::query()->tracked()->find($substituteId);

        if ($pengganti === null) {
            throw new RuntimeException('Pengganti yang dipilih tidak aktif atau tidak ikut diabsen.');
        }

        return $pengganti;
    }

    /** Beri tahu pengganti bahwa dia sedang ditunggu jawabannya. */
    protected function askSubstitute(Request $request): void
    {
        $user = $request->substitute?->user;

        if ($user === null) {
            return;
        }

        $this->notifier->send(
            $user,
            'Anda diminta jadi pengganti',
            "{$request->employee->name} mengajukan {$request->type->shortLabel()} ({$request->code}) dan menunjuk Anda sebagai pengganti.",
            route('karyawan.pengajuan.show', $request),
        );
    }

    // ---------------------------------------------------------------- submit

    public function submitLeave(Employee $employee, array $data): Request
    {
        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = Carbon::parse($data['end_date'])->startOfDay();

        if ($end->lessThan($start)) {
            throw new RuntimeException('Tanggal selesai tidak boleh lebih awal dari tanggal mulai.');
        }

        $this->lockGuard->ensureUnlocked($start);

        $days = $start->diffInDays($end) + 1;

        // Saldo dicek di sini, bukan saat approve, supaya karyawan tahu
        // sekarang — bukan tiga hari kemudian setelah manager membukanya.
        $this->leave->assertSufficientBalance($employee, (int) $data['leave_type_id'], $days);

        $pengganti = $this->assertSubstitute($employee, $data['substitute_employee_id'] ?? null);

        return DB::transaction(function () use ($employee, $data, $start, $end, $days, $pengganti) {
            // Menunggu pengganti dulu, bukan langsung ke manajer.
            $request = $this->createRequest($employee, RequestType::Leave, RequestStatus::PendingPeer, $pengganti);

            $request->leave()->create([
                'leave_type_id' => $data['leave_type_id'],
                'start_date' => $start,
                'end_date' => $end,
                'total_days' => $days,
                'reason' => $data['reason'],
                'handover_note' => $data['handover_note'] ?? null,
            ]);

            $this->leave->holdPending($employee, (int) $data['leave_type_id'], $days, $request);

            $this->askSubstitute($request);

            return $request;
        });
    }

    public function submitOvertime(Employee $employee, array $data, string $initiatedBy = 'employee'): Request
    {
        $workDate = Carbon::parse($data['work_date'])->startOfDay();
        $this->lockGuard->ensureUnlocked($workDate);

        $start = Carbon::parse($workDate->toDateString() . ' ' . $data['planned_start']);
        $end = Carbon::parse($workDate->toDateString() . ' ' . $data['planned_end']);

        // Lembur shift malam melewati tengah malam.
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        $minutes = (int) $start->diffInMinutes($end);
        $minimum = \App\Support\Settings::int('overtime.min_minutes', 60);

        if ($minutes < $minimum) {
            throw new RuntimeException("Lembur minimal {$minimum} menit. Pengajuan ini hanya {$minutes} menit.");
        }

        $pengganti = $this->assertSubstitute($employee, $data['substitute_employee_id'] ?? null);

        return DB::transaction(function () use ($employee, $data, $workDate, $minutes, $initiatedBy, $pengganti) {
            // Lembur yang ditugaskan manajer tetap butuh pengganti — orang yang
            // tinggal lebih lama malam ini biasanya perlu digantikan besok.
            $status = $initiatedBy === 'manager'
                ? RequestStatus::PendingManager
                : RequestStatus::PendingPeer;

            $request = $this->createRequest($employee, RequestType::Overtime, $status, $pengganti);

            // Manajer yang menunjuk langsung berarti pengganti sudah dibicarakan
            // di lapangan; jangan menahan penugasan mendesak menunggu balasan.
            if ($initiatedBy === 'manager') {
                $request->update(['substitute_accepted_at' => now()]);
            }

            $request->overtime()->create([
                'batch_id' => $data['batch_id'] ?? null,
                'work_date' => $workDate,
                'shift_id' => $data['shift_id'] ?? null,
                'planned_start' => $data['planned_start'],
                'planned_end' => $data['planned_end'],
                'planned_minutes' => $minutes,
                'initiated_by' => $initiatedBy,

                // Approval susulan untuk kejadian darurat. BR-14 tetap berlaku
                // (tanpa approval bukan lembur), hanya saja approval boleh
                // diberikan setelahnya — dan setiap kali itu terjadi, tercatat.
                'is_backdated' => $workDate->lessThan(today()),

                'reason' => $data['reason'],

                // Kode dibuat saat pengajuan lahir, bukan saat disetujui,
                // supaya nomor yang sama bisa dirujuk sejak awal percakapan.
                'secret_code' => OvertimeRequest::generateCode(),
            ]);

            $initiatedBy === 'manager'
                ? $this->announce($request, 'Penugasan lembur menunggu persetujuan')
                : $this->askSubstitute($request);

            return $request;
        });
    }

    public function submitSwap(Employee $employee, array $data): Request
    {
        $assignment = RosterAssignment::findOrFail($data['requester_assignment_id']);

        if ($assignment->employee_id !== $employee->id) {
            throw new RuntimeException('Jadwal yang ditukar bukan milik Anda.');
        }

        $this->lockGuard->ensureUnlocked($assignment->work_date);

        if ($assignment->work_date->lessThan(today())) {
            throw new RuntimeException('Tidak bisa menukar shift yang tanggalnya sudah lewat.');
        }

        // Pada tukar shift, rekan yang dituju SEKALIGUS penggantinya.
        $pengganti = $this->assertSubstitute($employee, $data['partner_employee_id'] ?? null);

        return DB::transaction(function () use ($employee, $data, $assignment, $pengganti) {
            $request = $this->createRequest($employee, RequestType::Swap, RequestStatus::PendingPeer, $pengganti);

            // Pengajuan menggantung tidak boleh mengubah roster di menit
            // terakhir: kedaluwarsa sehari sebelum tanggal shift.
            $request->update(['expires_at' => $assignment->work_date->copy()->subDay()->endOfDay()]);

            $request->swap()->create([
                'requester_assignment_id' => $assignment->id,
                'partner_employee_id' => $data['partner_employee_id'],
                'partner_assignment_id' => $data['partner_assignment_id'] ?? null,
                'reason' => $data['reason'],
            ]);

            $partner = Employee::find($data['partner_employee_id']);

            if ($partner?->user) {
                $this->notifier->send(
                    $partner->user,
                    'Permintaan tukar shift',
                    "{$employee->name} ingin menukar shift tanggal "
                        . $assignment->work_date->translatedFormat('d M Y'),
                    route('karyawan.pengajuan.show', $request),
                );
            }

            return $request;
        });
    }

    public function submitCorrection(Employee $employee, array $data): Request
    {
        $workDate = Carbon::parse($data['work_date'])->startOfDay();
        $this->lockGuard->ensureUnlocked($workDate);

        // Koreksi absensi tidak mengubah jadwal siapa pun, tapi kebijakan kafe
        // menyeragamkan seluruh pengajuan: selalu ada rekan yang tahu dan
        // membenarkan. Untuk koreksi, dia berperan sebagai saksi.
        $pengganti = $this->assertSubstitute($employee, $data['substitute_employee_id'] ?? null);

        return DB::transaction(function () use ($employee, $data, $workDate, $pengganti) {
            $request = $this->createRequest($employee, RequestType::Correction, RequestStatus::PendingPeer, $pengganti);

            $request->correction()->create([
                'work_date' => $workDate,
                'shift_key' => (int) ($data['shift_key'] ?? 0),
                'correction_type' => $data['correction_type'],
                'proposed_check_in' => $data['proposed_check_in'] ?? null,
                'proposed_check_out' => $data['proposed_check_out'] ?? null,
                'proposed_status' => $data['proposed_status'] ?? null,
                'reason' => $data['reason'],
            ]);

            $this->askSubstitute($request);

            return $request;
        });
    }

    // --------------------------------------------------------------- decide

    /**
     * Pengganti menerima atau menolak permintaan.
     *
     * Berlaku untuk SEMUA jenis pengajuan, bukan cuma tukar shift. Selama
     * pengganti belum menjawab, manajer bahkan tidak melihat pengajuannya —
     * tidak ada gunanya memutuskan cuti yang belum jelas siapa penutup
     * shift-nya.
     */
    public function peerRespond(Request $request, Employee $pengganti, bool $accepted, ?string $note = null): Request
    {
        if ($request->status !== RequestStatus::PendingPeer) {
            throw new RuntimeException('Pengajuan ini tidak sedang menunggu jawaban pengganti.');
        }

        if ($request->substitute_employee_id !== $pengganti->id) {
            throw new RuntimeException('Anda bukan pengganti yang ditunjuk pada pengajuan ini.');
        }

        return DB::transaction(function () use ($request, $accepted, $note) {
            if (! $accepted) {
                $request->update([
                    'substitute_rejected_at' => now(),
                    'substitute_note' => $note,
                    'status' => RequestStatus::Rejected,
                    'decision_note' => 'Pengganti tidak bersedia',
                ]);

                // Saldo cuti yang tadi ditahan harus dikembalikan.
                if ($request->type === RequestType::Leave) {
                    $this->leave->releasePending($request);
                }

                $request->swap?->update(['partner_rejected_at' => now(), 'partner_note' => $note]);

                AuditLogger::record('request.substitute_rejected', $request);
                $this->notifyRequester($request, 'Pengganti tidak bersedia');

                return $request;
            }

            $request->update([
                'substitute_accepted_at' => now(),
                'substitute_note' => $note,
                'status' => RequestStatus::PendingManager,
            ]);

            $request->swap?->update(['partner_accepted_at' => now(), 'partner_note' => $note]);

            AuditLogger::record('request.substitute_accepted', $request);
            $this->announce($request, 'Pengajuan menunggu persetujuan Anda');

            return $request;
        });
    }

    public function approve(Request $request, User $decider, ?string $note = null): Request
    {
        if ($request->status !== RequestStatus::PendingManager) {
            throw new RuntimeException('Pengajuan ini tidak sedang menunggu persetujuan manager.');
        }

        // Penjaga terakhir. Status seharusnya sudah menjamin ini, tapi aturan
        // yang menyangkut "siapa yang menutup shift" terlalu mahal kalau bocor
        // lewat jalur yang tidak terduga — misalnya perubahan status manual.
        if ($request->substitute_employee_id === null) {
            throw new RuntimeException('Pengajuan ini belum menunjuk pengganti.');
        }

        if (! $request->substituteConfirmed()) {
            throw new RuntimeException(
                'Pengganti (' . ($request->substitute?->name ?? '-') . ') belum menyatakan bersedia. '
                . 'Persetujuan menunggu konfirmasi pengganti lebih dulu.'
            );
        }

        return DB::transaction(function () use ($request, $decider, $note) {
            $request->update([
                'status' => RequestStatus::Approved,
                'decided_by' => $decider->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            // Efek yang khas per jenis. Inilah satu-satunya tempat pengajuan
            // boleh mengubah roster atau absensi.
            match ($request->type) {
                RequestType::Leave => $this->applyLeave($request),
                RequestType::Overtime => $this->applyOvertime($request),
                RequestType::Swap => $this->applySwap($request),
                RequestType::Correction => $this->applyCorrection($request, $decider),
            };

            AuditLogger::record('request.approved', $request, [], [
                'type' => $request->type->value,
                'employee' => $request->employee->name,
            ]);

            $this->notifyRequester($request, 'Pengajuan disetujui');

            return $request;
        });
    }

    public function reject(Request $request, User $decider, string $note): Request
    {
        if ($request->status->isFinal()) {
            throw new RuntimeException('Pengajuan ini sudah diputuskan sebelumnya.');
        }

        return DB::transaction(function () use ($request, $decider, $note) {
            $request->update([
                'status' => RequestStatus::Rejected,
                'decided_by' => $decider->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            // Saldo cuti yang tadi ditahan harus dikembalikan.
            if ($request->type === RequestType::Leave) {
                $this->leave->releasePending($request);
            }

            AuditLogger::record('request.rejected', $request, [], ['note' => $note]);
            $this->notifyRequester($request, 'Pengajuan ditolak');

            return $request;
        });
    }

    public function cancel(Request $request): Request
    {
        if ($request->status->isFinal()) {
            throw new RuntimeException('Pengajuan yang sudah diputuskan tidak bisa dibatalkan.');
        }

        if ($request->type === RequestType::Leave) {
            $this->leave->releasePending($request);
        }

        $request->update([
            'status' => RequestStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        AuditLogger::record('request.cancelled', $request);

        return $request;
    }

    // ---------------------------------------------------------------- apply

    protected function applyLeave(Request $request): void
    {
        $leave = $request->leave;
        $employee = $request->employee;

        // Roster berubah otomatis (BR-20).
        for ($date = $leave->start_date->copy(); $date->lessThanOrEqualTo($leave->end_date); $date->addDay()) {
            $this->roster->markLeave($employee, $date, $request->id);
        }

        $this->leave->consume($request);

        // Absensi hari-hari itu ditulis lewat lapisan koreksi, bukan langsung
        // ke tabel attendances, supaya recompute tidak menghapusnya.
        $status = $leave->leaveType->attendanceStatus();

        for ($date = $leave->start_date->copy(); $date->lessThanOrEqualTo($leave->end_date); $date->addDay()) {
            AttendanceAdjustment::create([
                'employee_id' => $employee->id,
                'work_date' => $date->toDateString(),
                'shift_key' => 0,
                'request_id' => $request->id,
                'type' => 'set_status',
                'value_status' => $status,
                'reason' => "Cuti disetujui: {$request->code}",
                'approved_by' => $request->decided_by,
                'approved_at' => now(),
            ]);
        }
    }

    /**
     * Approval lembur belum berarti lembur terjadi.
     *
     * Yang dibuat di sini adalah baris realisasi yang MENUNGGU konfirmasi;
     * jam aktualnya baru terisi dari fingerprint setelah orangnya benar-benar
     * bekerja. Yang dibayar min(disetujui, aktual).
     */
    protected function applyOvertime(Request $request): void
    {
        $overtime = $request->overtime;

        OvertimeRecord::updateOrCreate(
            [
                'employee_id' => $request->employee_id,
                'work_date' => $overtime->work_date,
                'overtime_request_id' => $overtime->request_id,
            ],
            [
                'approved_minutes' => $overtime->planned_minutes,
                'payable_minutes' => 0,
                'status' => 'pending_confirmation',
            ],
        );
    }

    protected function applySwap(Request $request): void
    {
        $swap = $request->swap;
        $mine = $swap->requesterAssignment;
        $theirs = $swap->partnerAssignment;

        if ($theirs === null) {
            // Rekan mengambil alih shift tanpa memberi shift balik.
            $mine->update([
                'employee_id' => $swap->partner_employee_id,
                'source' => 'swap',
                'source_request_id' => $request->id,
            ]);

            return;
        }

        // Tukar pemilik kedua jadwal.
        $mineEmployee = $mine->employee_id;
        $theirsEmployee = $theirs->employee_id;

        $mine->update([
            'employee_id' => $theirsEmployee,
            'source' => 'swap',
            'source_request_id' => $request->id,
        ]);

        $theirs->update([
            'employee_id' => $mineEmployee,
            'source' => 'swap',
            'source_request_id' => $request->id,
        ]);
    }

    /**
     * Koreksi absensi TIDAK ditulis langsung ke tabel attendances.
     *
     * Ia jadi baris di attendance_adjustments yang diterapkan ulang setiap
     * compute. Kalau ditulis langsung, cron yang jalan 15 menit kemudian akan
     * menghapus keputusan manager tanpa ada yang sadar.
     */
    protected function applyCorrection(Request $request, User $decider): void
    {
        $correction = $request->correction;

        // Karyawan mengajukan koreksi berdasarkan tanggal, bukan shift_key
        // internal. Kalau tanggal itu cuma punya satu jadwal — hampir selalu
        // begitu — pakai shift itu supaya koreksinya spesifik. Kalau ada dua
        // (double shift), biarkan 0 yang berarti berlaku untuk keduanya.
        $shiftKey = (int) $correction->shift_key;

        if ($shiftKey === 0) {
            $jadwal = RosterAssignment::query()
                ->where('employee_id', $request->employee_id)
                ->whereDate('work_date', $correction->work_date)
                ->working()
                ->get();

            $shiftKey = $jadwal->count() === 1 ? (int) $jadwal->first()->shift_id : 0;
        }

        $base = [
            'employee_id' => $request->employee_id,
            'work_date' => $correction->work_date->toDateString(),
            'shift_key' => $shiftKey,
            'request_id' => $request->id,
            'reason' => $correction->reason,
            'approved_by' => $decider->id,
            'approved_at' => now(),
        ];

        if ($correction->proposed_check_in) {
            AttendanceAdjustment::create($base + [
                'type' => 'set_check_in',
                'value_time' => $correction->proposed_check_in,
            ]);
        }

        if ($correction->proposed_check_out) {
            AttendanceAdjustment::create($base + [
                'type' => 'set_check_out',
                'value_time' => $correction->proposed_check_out,
            ]);
        }

        if ($correction->proposed_status) {
            AttendanceAdjustment::create($base + [
                'type' => 'set_status',
                'value_status' => $correction->proposed_status,
            ]);
        }
    }

    // ---------------------------------------------------------------- utils

    protected function createRequest(
        Employee $employee,
        RequestType $type,
        RequestStatus $status,
        ?Employee $pengganti = null,
    ): Request {
        return Request::create([
            'code' => $this->nextCode(),
            'branch_id' => Branch::current()->id,
            'type' => $type,
            'employee_id' => $employee->id,
            'substitute_employee_id' => $pengganti?->id,
            'status' => $status,
            'submitted_at' => now(),
        ]);
    }

    protected function nextCode(): string
    {
        $prefix = 'REQ-' . now()->format('Y-m');
        $count = Request::where('code', 'like', $prefix . '%')->count() + 1;

        return sprintf('%s-%04d', $prefix, $count);
    }

    protected function announce(Request $request, string $title): void
    {
        $this->notifier->sendToManagement(
            $title,
            "{$request->employee->name} — {$request->type->label()} ({$request->code})",
            route('manajer.pengajuan.show', $request),
            ['request_id' => $request->id],
        );
    }

    protected function notifyRequester(Request $request, string $title): void
    {
        $user = $request->employee->user;

        if ($user === null) {
            return;
        }

        $this->notifier->send(
            $user,
            $title,
            "{$request->type->label()} ({$request->code}) — {$request->status->label()}",
            route('karyawan.pengajuan.show', $request),
        );
    }
}
