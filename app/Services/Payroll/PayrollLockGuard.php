<?php

namespace App\Services\Payroll;

use App\Models\Branch;
use App\Models\PayrollPeriod;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Gerbang tunggal untuk aturan "periode terkunci menolak semua perubahan".
 *
 * Diletakkan di satu tempat, bukan dicek berulang di tiap controller, karena
 * aturan yang disalin ke sepuluh tempat pasti terlewat di tempat kesebelas —
 * dan tempat kesebelas itulah yang mengubah gaji yang sudah dibayar.
 */
class PayrollLockGuard
{
    public function lockedPeriodFor(Carbon $date, ?int $branchId = null): ?PayrollPeriod
    {
        $branchId ??= Branch::current()->id;

        return PayrollPeriod::query()
            ->where('branch_id', $branchId)
            ->where('status', 'locked')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();
    }

    public function isLocked(Carbon $date, ?int $branchId = null): bool
    {
        return $this->lockedPeriodFor($date, $branchId) !== null;
    }

    /**
     * @throws RuntimeException kalau tanggalnya berada di periode terkunci.
     */
    public function ensureUnlocked(Carbon $date, ?int $branchId = null): void
    {
        $period = $this->lockedPeriodFor($date, $branchId);

        if ($period !== null) {
            throw new RuntimeException(
                "Tanggal {$date->translatedFormat('d M Y')} berada di periode payroll {$period->code} "
                . 'yang sudah dikunci. Koreksi untuk periode ini dibayar sebagai penyesuaian '
                . 'di periode berikutnya, atau periodenya harus dibuka ulang lebih dulu.'
            );
        }
    }
}
