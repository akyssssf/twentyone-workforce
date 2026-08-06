<?php

namespace App\Services\Payroll;

use App\Enums\PayrollStatus;
use App\Models\Branch;
use App\Models\PayrollPeriod;
use App\Services\Audit\AuditLogger;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Pembuat dan pengunci periode penggajian.
 *
 * Periode berjalan dari tanggal 21 bulan sebelumnya sampai tanggal 20 bulan
 * ini, dibayar tanggal 21 (D-01). Kodenya mengikuti bulan pembayaran, jadi
 * "2026-08" berarti gaji yang dibayarkan 21 Agustus untuk kerja 21 Juli-20
 * Agustus.
 *
 * Tanggalnya diambil dari settings, bukan ditanam di kode, supaya kalau kafe
 * memindahkan gajian ke tanggal 25 nanti, yang berubah cuma data.
 */
class PayrollPeriodFactory
{
    public function forMonth(int $year, int $month): PayrollPeriod
    {
        $startDay = Settings::int('payroll.period_start_day', 21);
        $payDay = Settings::int('payroll.pay_day', 21);

        $payDate = Carbon::create($year, $month, $payDay)->startOfDay();
        $start = $payDate->copy()->subMonthNoOverflow()->day($startDay)->startOfDay();
        $end = $payDate->copy()->day($startDay)->subDay()->startOfDay();

        return PayrollPeriod::firstOrCreate(
            [
                'branch_id' => Branch::current()->id,
                'code' => sprintf('%04d-%02d', $year, $month),
            ],
            [
                'start_date' => $start,
                'end_date' => $end,
                'pay_date' => $payDate,
                'status' => PayrollStatus::Open,
            ],
        );
    }

    /** Periode yang mencakup tanggal tertentu. */
    public function covering(Carbon $date): PayrollPeriod
    {
        $startDay = Settings::int('payroll.period_start_day', 21);

        // Tanggal 21 ke atas sudah masuk periode bulan berikutnya.
        $month = $date->day >= $startDay
            ? $date->copy()->addMonthNoOverflow()
            : $date->copy();

        return $this->forMonth((int) $month->year, (int) $month->month);
    }

    public function approve(PayrollPeriod $period): PayrollPeriod
    {
        if ($period->status !== PayrollStatus::Generated) {
            throw new RuntimeException('Hanya periode yang sudah dihitung yang bisa disetujui.');
        }

        $period->update([
            'status' => PayrollStatus::Approved,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        // Slip baru terlihat karyawan setelah disetujui. Sebelum itu angkanya
        // masih bisa berubah, dan slip yang berubah-ubah menghancurkan
        // kepercayaan lebih cepat daripada slip yang terlambat.
        $period->activeRun()?->payslips()->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        AuditLogger::record('payroll.approved', $period);

        return $period;
    }

    public function lock(PayrollPeriod $period): PayrollPeriod
    {
        if ($period->status !== PayrollStatus::Approved) {
            throw new RuntimeException('Kunci periode hanya setelah payroll disetujui.');
        }

        $period->update([
            'status' => PayrollStatus::Locked,
            'locked_by' => auth()->id(),
            'locked_at' => now(),
        ]);

        AuditLogger::record('payroll.locked', $period, [], [
            'range' => $period->label(),
        ]);

        return $period;
    }

    /**
     * Buka kunci periode.
     *
     * Ini jalur luar biasa, bukan jalur rutin. Koreksi kecil untuk satu-dua
     * orang seharusnya lewat penyesuaian di periode berikutnya — membuka kunci
     * berarti membatalkan slip gaji yang sudah diterima semua orang.
     */
    public function reopen(PayrollPeriod $period, string $reason): PayrollPeriod
    {
        if (! $period->isLocked()) {
            throw new RuntimeException('Periode ini tidak sedang terkunci.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('Alasan membuka kunci wajib diisi.');
        }

        $period->update([
            'status' => PayrollStatus::Reopened,
            'reopened_by' => auth()->id(),
            'reopened_at' => now(),
            'reopen_reason' => $reason,
        ]);

        AuditLogger::record('payroll.reopened', $period, [], ['reason' => $reason]);

        return $period;
    }
}
