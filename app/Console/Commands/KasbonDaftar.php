<?php

namespace App\Console\Commands;

use App\Models\CashAdvance;
use App\Models\CashAdvanceInstallment;
use App\Models\PayrollPeriod;
use Illuminate\Console\Command;

/**
 * Daftar kasbon yang masih berjalan, atau semua kalau diminta.
 */
class KasbonDaftar extends Command
{
    protected $signature = 'kasbon:daftar
                            {--bulan= : Hanya cicilan yang jatuh tempo di gajian ini, format 2026-10}
                            {--pin= : Hanya karyawan ini}
                            {--semua : Ikutkan yang sudah lunas/batal}';

    protected $description = 'Daftar kasbon dan cicilannya';

    public function handle(): int
    {
        $query = CashAdvance::query()
            ->with(['employee', 'installments.period'])
            ->when(! $this->option('semua'), fn ($q) => $q->where('status', 'disbursed'))
            ->when($this->option('pin'), fn ($q, $pin) => $q->whereHas('employee', fn ($e) => $e->where('pin_device', $pin)))
            ->when($this->option('bulan'), function ($q, $bulan) {
                $periode = PayrollPeriod::where('code', $bulan)->first();

                if ($periode === null) {
                    return $q->whereRaw('1 = 0');
                }

                return $q->whereHas('installments', fn ($i) => $i->where('payroll_period_id', $periode->id));
            })
            ->orderByDesc('disbursed_at')
            ->orderByDesc('id');

        $daftar = $query->get();

        if ($daftar->isEmpty()) {
            $this->info('Tidak ada kasbon'.($this->option('semua') ? '' : ' yang masih berjalan').'.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($daftar as $kasbon) {
            $rows[] = [
                $kasbon->id,
                $kasbon->employee?->pin_device,
                $kasbon->employee?->name,
                $kasbon->disbursed_at?->format('d/m/Y'),
                'Rp '.number_format($kasbon->amount, 0, ',', '.'),
                'Rp '.number_format($kasbon->remaining(), 0, ',', '.'),
                $kasbon->installments->map(fn (CashAdvanceInstallment $c) => sprintf(
                    '%s %s %s',
                    $c->period?->code ?? '?',
                    number_format($c->amount, 0, ',', '.'),
                    $this->statusCicilan($c->status),
                ))->implode(' | '),
                $kasbon->reason,
            ];
        }

        $this->table(['ID', 'PIN', 'Nama', 'Tanggal', 'Jumlah', 'Sisa', 'Cicilan (gajian · Rp · status)', 'Keterangan'], $rows);
        $this->line('Sisa = cicilan yang belum masuk slip. Batalkan dengan: php artisan kasbon:batal ID');

        return self::SUCCESS;
    }

    protected function statusCicilan(string $status): string
    {
        return match ($status) {
            'scheduled' => 'belum',
            'deducted' => 'terpotong',
            'skipped' => 'dibatalkan',
            'written_off' => 'dihapus',
            default => $status,
        };
    }
}
