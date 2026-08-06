<?php

namespace App\Services\Requests;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedger;
use App\Models\LeaveType;
use App\Models\Request;
use RuntimeException;

/**
 * Saldo cuti.
 *
 * Dua angka yang dikurangi, bukan satu: `pending_days` menahan saldo begitu
 * pengajuan masuk, `used_days` baru terisi setelah disetujui. Tanpa penahanan
 * itu, karyawan bisa mengajukan 12 hari cuti tiga kali sebelum satu pun
 * diputuskan, dan ketiganya lolos pengecekan saldo.
 *
 * Setiap perubahan saldo dicatat di leave_ledger, supaya pertanyaan "kok sisa
 * cuti saya berkurang 2 hari?" bisa dijawab dari data, bukan dari ingatan.
 */
class LeaveService
{
    public function balanceFor(Employee $employee, int $leaveTypeId, ?int $year = null): LeaveBalance
    {
        $year ??= (int) now()->year;
        $type = LeaveType::findOrFail($leaveTypeId);

        return LeaveBalance::firstOrCreate(
            [
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveTypeId,
                'year' => $year,
            ],
            [
                'entitlement_days' => $type->default_entitlement_days,
            ],
        );
    }

    public function assertSufficientBalance(Employee $employee, int $leaveTypeId, float $days): void
    {
        $type = LeaveType::findOrFail($leaveTypeId);

        // Izin dan sakit tidak memotong kuota, jadi tidak perlu dicek.
        if (! $type->deducts_balance) {
            return;
        }

        if ($days > $type->max_days_per_request) {
            throw new RuntimeException(
                "Maksimal {$type->max_days_per_request} hari untuk satu pengajuan {$type->name}."
            );
        }

        $balance = $this->balanceFor($employee, $leaveTypeId);

        if ($balance->remaining() < $days) {
            throw new RuntimeException(
                "Sisa {$type->name} tinggal {$balance->remaining()} hari, tidak cukup untuk {$days} hari."
            );
        }
    }

    public function holdPending(Employee $employee, int $leaveTypeId, float $days, Request $request): void
    {
        $type = LeaveType::findOrFail($leaveTypeId);

        if (! $type->deducts_balance) {
            return;
        }

        $balance = $this->balanceFor($employee, $leaveTypeId);
        $balance->increment('pending_days', $days);

        $this->log($balance, $request, -$days, 'usage', 'Ditahan menunggu keputusan');
    }

    /** Pengajuan disetujui: pending berpindah jadi terpakai. */
    public function consume(Request $request): void
    {
        $leave = $request->leave;
        $type = $leave->leaveType;

        if (! $type->deducts_balance) {
            return;
        }

        $balance = $this->balanceFor($request->employee, $type->id, (int) $leave->start_date->year);
        $days = (float) $leave->total_days;

        $balance->decrement('pending_days', $days);
        $balance->increment('used_days', $days);
    }

    /** Pengajuan ditolak atau dibatalkan: saldo yang ditahan dikembalikan. */
    public function releasePending(Request $request): void
    {
        $leave = $request->leave;

        if ($leave === null || ! $leave->leaveType->deducts_balance) {
            return;
        }

        $balance = $this->balanceFor($request->employee, $leave->leave_type_id, (int) $leave->start_date->year);
        $days = (float) $leave->total_days;

        $balance->decrement('pending_days', min($days, (float) $balance->pending_days));

        $this->log($balance, $request, $days, 'reversal', 'Pengajuan tidak jadi');
    }

    protected function log(LeaveBalance $balance, ?Request $request, float $delta, string $type, ?string $note = null): void
    {
        LeaveLedger::create([
            'leave_balance_id' => $balance->id,
            'request_id' => $request?->id,
            'delta_days' => $delta,
            'type' => $type,
            'note' => $note,
            'created_by' => auth()->id(),
        ]);
    }
}
