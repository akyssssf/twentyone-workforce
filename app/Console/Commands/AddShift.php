<?php

namespace App\Console\Commands;

use App\Models\Shift;
use Illuminate\Console\Command;

/**
 * Buat shift baru, mis. jam kerja khusus manajer (08:00-23:30).
 *
 * Sebelum ini shift hanya bisa diubah (shift:edit), tidak dibuat, jadi
 * orang dengan jam kerja yang tidak cocok dengan pagi/malam/middle tidak
 * punya tempat di roster.
 */
class AddShift extends Command
{
    protected $signature = 'shift:tambah
                            {kode : Kode pendek huruf kecil, mis. manager}
                            {nama : Nama shift, mis. "Shift Manager"}
                            {mulai : Jam masuk HH:MM}
                            {selesai : Jam pulang HH:MM}
                            {--warna=#0ea5e9 : Warna di roster}
                            {--sembunyikan-jam : Jangan tampilkan jam ke karyawan}';

    protected $description = 'Buat shift baru dengan jam kerja khusus';

    public function handle(): int
    {
        $kode = strtolower(trim((string) $this->argument('kode')));

        if (! preg_match('/^[a-z][a-z0-9_]{1,15}$/', $kode)) {
            $this->error('Kode harus huruf kecil/angka, 2-16 karakter, mis. manager.');

            return self::FAILURE;
        }

        if (Shift::where('code', $kode)->exists()) {
            $this->error("Shift dengan kode '{$kode}' sudah ada.");

            return self::FAILURE;
        }

        $mulai = $this->jam((string) $this->argument('mulai'));
        $selesai = $this->jam((string) $this->argument('selesai'));

        if ($mulai === null || $selesai === null) {
            $this->error('Jam harus berformat HH:MM, mis. 08:00 atau 23:30.');

            return self::FAILURE;
        }

        $shift = Shift::create([
            'code' => $kode,
            'name' => trim((string) $this->argument('nama')),
            'start_time' => $mulai,
            'end_time' => $selesai,
            'crosses_midnight' => substr($selesai, 0, 5) <= substr($mulai, 0, 5),
            'break_minutes' => 60,
            'is_break_paid' => true,
            'window_before_hours' => 4,
            'window_after_hours' => 4,
            'color' => (string) $this->option('warna'),
            'show_hours' => ! $this->option('sembunyikan-jam'),
            'is_active' => true,
        ]);

        $this->info(sprintf('Shift "%s" (%s) dibuat: %s-%s.', $shift->name, $shift->code, substr($mulai, 0, 5), substr($selesai, 0, 5)));
        $this->line('Pakai di roster: <comment>php artisan roster:set PIN 2026-11-16='.$shift->code.'</comment>');

        return self::SUCCESS;
    }

    protected function jam(string $masukan): ?string
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($masukan), $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return sprintf('%02d:%02d:00', $m[1], $m[2]);
    }
}
