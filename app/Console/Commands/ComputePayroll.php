<?php

namespace App\Console\Commands;

use App\Models\Payslip;
use App\Services\Payroll\PayrollGenerator;
use App\Services\Payroll\PayrollPeriodFactory;
use App\Support\Durasi;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Hitung payroll satu periode dari terminal, atau sebagai estimasi harian
 * oleh cron untuk periode yang sedang berjalan.
 *
 * Tanpa argumen: periode yang mencakup hari ini. Hasilnya sama persis dengan
 * tombol "Hitung" di web — draf yang tidak dilihat karyawan sampai disetujui.
 */
class ComputePayroll extends Command
{
    protected $signature = 'payroll:hitung
                            {bulan? : Kode periode, mis. 2026-10 (bawaan: periode yang mencakup hari ini)}
                            {--otomatis : Dipanggil cron: periode yang sudah disetujui/dikunci dilewati tanpa error}';

    protected $description = 'Hitung (ulang) payroll satu periode; tanpa argumen = periode berjalan';

    public function handle(PayrollGenerator $generator, PayrollPeriodFactory $periods): int
    {
        $bulan = $this->argument('bulan');

        if ($bulan !== null && ! preg_match('/^(\d{4})-(\d{2})$/', $bulan, $m)) {
            $this->error('Format bulan harus seperti 2026-10.');

            return self::FAILURE;
        }

        $period = $bulan !== null
            ? $periods->forMonth((int) $m[1], (int) $m[2])
            : $periods->covering(Carbon::today());

        if (! $period->status->canGenerate()) {
            $pesan = "Periode {$period->code} berstatus {$period->status->label()}; tidak dihitung ulang.";

            if ($this->option('otomatis')) {
                $this->line($pesan);

                return self::SUCCESS;
            }

            $this->error($pesan);

            return self::FAILURE;
        }

        try {
            $run = $generator->generate($period, $this->option('otomatis') ? 'otomatis' : 'manual');
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $berjalan = $period->end_date->isFuture();

        $this->info(sprintf(
            'Payroll %s (%s) versi %d: %d karyawan, total THP Rp %s%s',
            $period->code,
            $period->rentangHitung(),
            $run->version,
            $run->employee_count,
            number_format($run->total_take_home_pay, 0, ',', '.'),
            $berjalan ? ' — ESTIMASI, absensi sampai hari ini' : '',
        ));

        $bonus = (int) $run->payslips()->sum('total_bonus');

        if ($bonus > 0) {
            $this->line('Bonus Rp '.number_format($bonus, 0, ',', '.').' dibayar TERPISAH, di luar THP di atas.');
        }

        $this->table(
            ['Nama', 'Hadir', 'Alpha', 'Telat', 'Lembur', 'Potongan', 'THP', 'Bonus'],
            $run->payslips()->with('employee')->get()
                ->sortBy(fn (Payslip $s) => $s->employee?->name)
                ->map(fn (Payslip $s) => [
                    $s->employee?->name,
                    $s->present_days,
                    $s->absent_days,
                    $s->late_count.'x',
                    Durasi::menit((int) $s->overtime_minutes),
                    number_format($s->total_deduction, 0, ',', '.'),
                    number_format($s->take_home_pay, 0, ',', '.'),
                    $s->total_bonus > 0 ? number_format($s->total_bonus, 0, ',', '.') : '—',
                ])->all(),
        );

        return self::SUCCESS;
    }
}
