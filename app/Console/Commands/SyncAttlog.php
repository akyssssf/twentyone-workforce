<?php

namespace App\Console\Commands;

use App\Services\Fingerspot\AttlogSynchronizer;
use App\Services\Fingerspot\FingerspotException;
use App\Support\DateInput;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Tarik log absensi dari Fingerspot lewat get_attlog.
 *
 * Ini jalur cadangan. Jalur utamanya tetap webhook realtime; command ini
 * menambal yang kelewat. Karena sifatnya menarik, dia sudah bisa dipakai
 * bahkan sebelum aplikasi punya alamat publik.
 */
class SyncAttlog extends Command
{
    protected $signature = 'attendance:sync
                            {--from= : Tanggal awal (YYYY-MM-DD)}
                            {--to= : Tanggal akhir (YYYY-MM-DD)}
                            {--days=2 : Kalau tanpa tanggal, tarik N hari terakhir}
                            {--no-compute : Jangan hitung ulang rekap setelah menarik}';

    protected $description = 'Tarik log absensi dari Fingerspot untuk menambal scan yang kelewat';

    public function handle(AttlogSynchronizer $sync): int
    {
        $timezone = config('attendance.timezone', 'Asia/Jakarta');

        try {
            [$from, $to] = $this->rentang($timezone);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Menarik data {$from->toDateString()} s/d {$to->toDateString()}...");

        try {
            $hasil = $sync->syncRange($from, $to);
        } catch (FingerspotException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Keterangan', 'Jumlah'], [
            ['Permintaan ke API', $hasil['chunks']],
            ['Baris diterima', $hasil['rows']],
            ['Scan baru tersimpan', $hasil['created']],
            ['Duplikat dilewati', $hasil['duplicate']],
            ['Baris rusak dilewati', $hasil['invalid']],
        ]);

        if ($hasil['rows'] === 0) {
            $this->newLine();
            $this->warn('Fingerspot tidak mengembalikan satu baris pun untuk rentang ini.');
            $this->line('Wajar kalau memang belum ada yang scan. Kalau seharusnya ada,');
            $this->line('pastikan scan di mesin benar-benar berhasil, bukan gagal autentikasi.');
        }

        if ($hasil['duplicate'] > 0) {
            $this->line('Duplikat bukan masalah: artinya scan itu sudah lebih dulu masuk lewat webhook.');
        }

        // Data baru tidak ada gunanya kalau rekapnya belum ikut diperbarui.
        if ($hasil['created'] > 0 && ! $this->option('no-compute')) {
            $this->newLine();
            $this->call('attendance:compute', [
                '--from' => $from->toDateString(),
                '--to' => $to->toDateString(),
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function rentang(string $timezone): array
    {
        $hariIni = Carbon::today($timezone);

        if ($this->option('from') || $this->option('to')) {
            $from = $this->option('from') ? $this->tanggal($this->option('from'), 'from') : $hariIni->copy();
            $to = $this->option('to') ? $this->tanggal($this->option('to'), 'to') : $hariIni->copy();

            if ($from->greaterThan($to)) {
                throw new \InvalidArgumentException('--from tidak boleh melewati --to.');
            }

            return [$from, $to];
        }

        $days = max(1, (int) $this->option('days'));

        return [$hariIni->copy()->subDays($days - 1), $hariIni];
    }

    protected function tanggal(string $nilai, string $opsi): Carbon
    {
        return DateInput::parseOrFail($nilai, "--{$opsi}");
    }
}
