<?php

namespace App\Console\Commands;

use App\Models\Holiday;
use App\Support\DateInput;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Kelola libur bersama: hari raya, tanggal merah, atau hari kafe tutup.
 *
 * Berbeda dari libur mingguan yang diatur per karyawan lewat employee:edit
 * --off-days. Yang di sini berlaku untuk semua orang sekaligus.
 */
class ManageHolidays extends Command
{
    protected $signature = 'holiday
                            {aksi : add, list, atau remove}
                            {tanggal? : Tanggal (YYYY-MM-DD), untuk add dan remove}
                            {nama?* : Nama libur, untuk add}
                            {--tetap-buka : Tanggal merah tapi kafe tetap buka, jadi tetap wajib masuk}
                            {--tahun= : Batasi daftar ke satu tahun}';

    protected $description = 'Kelola libur bersama (add, list, remove)';

    public function handle(): int
    {
        return match ($this->argument('aksi')) {
            'add' => $this->tambah(),
            'list' => $this->daftar(),
            'remove' => $this->hapus(),
            default => $this->aksiTidakDikenal(),
        };
    }

    protected function tambah(): int
    {
        $tanggal = DateInput::parse($this->argument('tanggal'));

        if ($tanggal === null) {
            $this->error('Sebutkan tanggal yang sah, format YYYY-MM-DD.');
            $this->line('Contoh: php artisan holiday add 2026-08-17 HUT RI');

            return self::FAILURE;
        }

        $nama = trim(implode(' ', (array) $this->argument('nama')));

        if ($nama === '') {
            $this->error('Sebutkan nama liburnya.');

            return self::FAILURE;
        }

        $tutup = ! $this->option('tetap-buka');

        $holiday = Holiday::updateOrCreate(
            ['date' => $tanggal],
            ['name' => $nama, 'is_closed' => $tutup],
        );

        $this->info(sprintf(
            '%s: %s (%s).',
            $holiday->date->translatedFormat('l, d F Y'),
            $holiday->name,
            $tutup ? 'kafe tutup, semua libur' : 'kafe tetap buka, tetap wajib masuk',
        ));

        if ($tutup) {
            $this->newLine();
            $this->line('Jalankan <comment>php artisan attendance:compute --date='.$tanggal->toDateString().'</comment>');
            $this->line('kalau tanggal itu sudah lewat dan terlanjur terhitung alpha.');
        }

        return self::SUCCESS;
    }

    protected function daftar(): int
    {
        $tahun = $this->option('tahun');

        $holidays = Holiday::query()
            ->when($tahun, fn ($q) => $q->whereYear('date', (int) $tahun))
            ->orderBy('date')
            ->get();

        if ($holidays->isEmpty()) {
            $this->warn('Belum ada libur bersama terdaftar.');
            $this->line('Tambahkan dengan: php artisan holiday add 2026-08-17 HUT RI');

            return self::SUCCESS;
        }

        $this->table(
            ['Tanggal', 'Hari', 'Nama', 'Kafe'],
            $holidays->map(fn (Holiday $h) => [
                $h->date->toDateString(),
                $h->date->translatedFormat('l'),
                $h->name,
                $h->is_closed ? 'tutup' : 'tetap buka',
            ])->all(),
        );

        return self::SUCCESS;
    }

    protected function hapus(): int
    {
        $tanggal = DateInput::parse($this->argument('tanggal'));

        if ($tanggal === null) {
            $this->error('Sebutkan tanggal yang sah, format YYYY-MM-DD.');

            return self::FAILURE;
        }

        $holiday = Holiday::whereDate('date', $tanggal)->first();

        if ($holiday === null) {
            $this->warn("Tidak ada libur terdaftar pada {$tanggal->toDateString()}.");

            return self::SUCCESS;
        }

        $nama = $holiday->name;
        $holiday->delete();

        $this->info("Libur \"{$nama}\" pada {$tanggal->toDateString()} dihapus.");
        $this->line('Jalankan <comment>php artisan attendance:compute --date='.$tanggal->toDateString().'</comment> untuk menghitung ulang.');

        return self::SUCCESS;
    }

    protected function aksiTidakDikenal(): int
    {
        $this->error('Aksi harus salah satu dari: add, list, remove.');
        $this->newLine();
        $this->line('  php artisan holiday add 2026-08-17 HUT RI');
        $this->line('  php artisan holiday list --tahun='.Carbon::now()->year);
        $this->line('  php artisan holiday remove 2026-08-17');

        return self::FAILURE;
    }
}
