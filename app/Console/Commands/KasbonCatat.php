<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Payroll\KasbonService;
use App\Support\DateInput;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Catat kasbon dari terminal: siapa, berapa, dipotong mulai gajian bulan apa.
 *
 * Bisa beberapa orang sekaligus (--data=pin:jumlah diulang) karena kasbon
 * biasanya dicatat serentak menjelang gajian. Semua-atau-tidak-sama-sekali:
 * satu PIN salah, tidak ada yang tersimpan.
 */
class KasbonCatat extends Command
{
    protected $signature = 'kasbon:catat
                            {--data=* : Format pin:jumlah, contoh 15:500000 — boleh diulang}
                            {--bulan= : Gajian pertama tempat dipotong, format 2026-10 (bawaan: periode yang mencakup hari ini)}
                            {--cicilan=1 : Dipotong berapa kali gajian (1–12)}
                            {--tanggal= : Tanggal uangnya diterima (bawaan: hari ini)}
                            {--keterangan= : Catatan, mis. "kasbon keperluan keluarga"}
                            {--dry-run : Tampilkan saja, jangan simpan}';

    protected $description = 'Catat kasbon karyawan, dipotong otomatis saat payroll dihitung';

    public function handle(KasbonService $kasbon): int
    {
        $tanggal = $this->option('tanggal')
            ? DateInput::parseOrFail((string) $this->option('tanggal'), 'tanggal')
            : Carbon::today();

        $bulan = $this->option('bulan') ?: $kasbon->periodeBerikutnya($tanggal)->code;

        if (! preg_match('/^\d{4}-\d{2}$/', $bulan)) {
            $this->error('Format --bulan harus seperti 2026-10.');

            return self::FAILURE;
        }

        $cicilan = (int) $this->option('cicilan');

        if ($cicilan < 1 || $cicilan > 12) {
            $this->error('--cicilan harus 1 sampai 12.');

            return self::FAILURE;
        }

        $baris = [];

        foreach ($this->option('data') as $item) {
            if (! preg_match('/^(\d+):(\d+)$/', trim((string) $item), $m)) {
                $this->error("Format salah: \"{$item}\". Harus pin:jumlah, contoh 15:500000.");

                return self::FAILURE;
            }

            $employee = Employee::where('pin_device', $m[1])->first();

            if ($employee === null) {
                $this->error("PIN {$m[1]} tidak ditemukan. Tidak ada yang disimpan.");

                return self::FAILURE;
            }

            if ((int) $m[2] <= 0) {
                $this->error("Jumlah untuk PIN {$m[1]} harus lebih dari nol.");

                return self::FAILURE;
            }

            $baris[] = [$employee, (int) $m[2]];
        }

        if ($baris === []) {
            $this->error('Tidak ada data. Pakai --data=pin:jumlah, contoh --data=15:500000');

            return self::FAILURE;
        }

        $this->table(
            ['PIN', 'Nama', 'Kasbon', 'Cicilan', 'Sisa kasbon lama'],
            collect($baris)->map(fn ($b) => [
                $b[0]->pin_device,
                $b[0]->name,
                'Rp '.number_format($b[1], 0, ',', '.'),
                $cicilan > 1 ? $cicilan.'x ± Rp '.number_format(intdiv($b[1], $cicilan), 0, ',', '.') : '1x',
                'Rp '.number_format($kasbon->sisaUntuk($b[0]), 0, ',', '.'),
            ])->all(),
        );

        $this->info('Total: Rp '.number_format(collect($baris)->sum(fn ($b) => $b[1]), 0, ',', '.')
            ." · dipotong mulai gajian {$bulan}"
            .($cicilan > 1 ? " selama {$cicilan} bulan" : ''));

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN: tidak ada yang disimpan.');

            return self::SUCCESS;
        }

        try {
            foreach ($baris as [$employee, $jumlah]) {
                $kasbon->catat($employee, $jumlah, $bulan, $cicilan, $this->option('keterangan'), $tanggal);
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(count($baris).' kasbon tersimpan. Potongannya masuk slip begitu payroll '.$bulan.' dihitung (ulang).');
        $this->line('Cek: php artisan kasbon:daftar');

        return self::SUCCESS;
    }
}
