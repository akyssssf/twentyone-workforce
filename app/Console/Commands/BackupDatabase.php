<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Salinan database.
 *
 * Payroll yang hilang bukan masalah teknis, itu masalah hukum: slip gaji yang
 * sudah dibayar harus tetap bisa dibuka bertahun-tahun kemudian.
 *
 * Untuk SQLite dipakai perintah bawaannya (VACUUM INTO), bukan menyalin berkas
 * dengan cp. Menyalin file yang sedang ditulis — dan di sini SELALU ada yang
 * menulis, karena mesin mengirim scan kapan saja — menghasilkan salinan rusak
 * yang baru ketahuan saat dibutuhkan.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup
                            {--keep=14 : Berapa salinan terakhir yang disimpan}';

    protected $description = 'Buat salinan database yang aman dipulihkan';

    public function handle(): int
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'sqlite') {
            $this->error("Command ini baru mendukung SQLite, bukan {$driver}.");
            $this->line('Untuk MySQL/PostgreSQL pakai mysqldump/pg_dump lewat cron server.');

            return self::FAILURE;
        }

        // VACUUM INTO menolak berjalan di dalam transaksi. Kalau dibiarkan,
        // pesannya keluar sebagai galat SQL mentah yang tidak memberi tahu apa
        // pun tentang cara membetulkannya.
        if (DB::transactionLevel() > 0) {
            $this->error('Tidak bisa membuat salinan di dalam transaksi database.');
            $this->line('Jalankan command ini dari cron atau terminal, bukan dari dalam kode yang sedang bertransaksi.');

            return self::FAILURE;
        }

        $tujuan = storage_path('app/backups');
        File::ensureDirectoryExists($tujuan);

        $nama = 'db-'.now()->format('Y-m-d_His').'.sqlite';
        $path = $tujuan.'/'.$nama;

        // VACUUM INTO menghasilkan salinan yang konsisten walau ada transaksi
        // berjalan, sekaligus memampatkan halaman yang sudah tidak terpakai.
        DB::statement('VACUUM INTO ?', [$path]);

        $ukuran = round(File::size($path) / 1024);
        $this->info("Salinan dibuat: {$nama} ({$ukuran} KB)");

        $this->buangYangLama((int) $this->option('keep'), $tujuan);

        return self::SUCCESS;
    }

    protected function buangYangLama(int $simpan, string $tujuan): void
    {
        $berkas = collect(File::files($tujuan))
            ->filter(fn ($f) => str_starts_with($f->getFilename(), 'db-'))
            ->sortByDesc(fn ($f) => $f->getFilename())
            ->values();

        if ($berkas->count() <= $simpan) {
            return;
        }

        $dibuang = $berkas->slice($simpan);

        $dibuang->each(fn ($f) => File::delete($f->getPathname()));

        $this->line("Membuang {$dibuang->count()} salinan lama, menyisakan {$simpan} terbaru.");
    }
}
