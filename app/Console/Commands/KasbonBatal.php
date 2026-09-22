<?php

namespace App\Console\Commands;

use App\Models\CashAdvance;
use App\Services\Payroll\KasbonService;
use Illuminate\Console\Command;

/**
 * Batalkan kasbon yang salah catat. Cicilan yang sudah masuk slip tidak
 * ditarik kembali — hanya yang belum terpotong yang dibatalkan.
 */
class KasbonBatal extends Command
{
    protected $signature = 'kasbon:batal
                            {id : ID kasbon dari kasbon:daftar}
                            {--alasan= : Kenapa dibatalkan}
                            {--ya : Jangan tanya konfirmasi}';

    protected $description = 'Batalkan cicilan kasbon yang belum terpotong';

    public function handle(KasbonService $service): int
    {
        $kasbon = CashAdvance::with(['employee', 'installments'])->find((int) $this->argument('id'));

        if ($kasbon === null) {
            $this->error("Kasbon #{$this->argument('id')} tidak ada. Lihat: php artisan kasbon:daftar");

            return self::FAILURE;
        }

        if ($kasbon->status !== 'disbursed') {
            $this->error("Kasbon #{$kasbon->id} sudah berstatus {$kasbon->status}; tidak ada yang bisa dibatalkan.");

            return self::FAILURE;
        }

        $belum = $kasbon->installments->where('status', 'scheduled');
        $sudah = $kasbon->installments->where('status', 'deducted');

        $this->line(sprintf('%s (PIN %s) · kasbon Rp %s', $kasbon->employee?->name, $kasbon->employee?->pin_device, number_format($kasbon->amount, 0, ',', '.')));
        $this->line(sprintf('  belum terpotong : %d cicilan, Rp %s — akan dibatalkan', $belum->count(), number_format($belum->sum('amount'), 0, ',', '.')));

        if ($sudah->isNotEmpty()) {
            $this->warn(sprintf('  sudah terpotong : %d cicilan, Rp %s — TIDAK ditarik kembali', $sudah->count(), number_format($sudah->sum('amount'), 0, ',', '.')));
        }

        if ($belum->isEmpty()) {
            $this->error('Semua cicilan sudah terpotong; tidak ada yang bisa dibatalkan.');

            return self::FAILURE;
        }

        if (! $this->option('ya') && ! $this->confirm('Lanjutkan?', false)) {
            $this->line('Batal, tidak ada yang diubah.');

            return self::SUCCESS;
        }

        $service->batalkan($kasbon, $this->option('alasan'));

        $this->info("Kasbon #{$kasbon->id} dibatalkan. Kalau payroll bulan ini sudah dihitung, hitung ulang supaya potongannya hilang dari slip.");

        return self::SUCCESS;
    }
}
