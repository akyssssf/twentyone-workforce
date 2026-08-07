<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Pengganti `schedule:run` untuk hosting yang mematikan proc_open.
 *
 * Scheduler bawaan Laravel selalu men-spawn tiap command terjadwal lewat
 * Symfony Process (proc_open) — bahkan yang dijalankan foreground tanpa
 * runInBackground(). Di hosting bersama yang proc_open-nya dimatikan (lazim
 * di CloudLinux/CageFS demi keamanan), itu bikin SEMUA command terjadwal
 * gagal dengan error yang sama persis: "The Process class relies on
 * proc_open, which is not available on your PHP installation."
 *
 * Command ini menjalankan artisan command langsung di proses yang sama lewat
 * Artisan::call(), tidak pernah lewat Process, jadi aman dari pembatasan itu.
 * Daftar tugas & jadwalnya SENGAJA disalin manual dari routes/console.php,
 * bukan dibaca otomatis dari situ — kalau jadwal produksi berubah, ubah juga
 * daftar di bawah ini.
 */
class RunScheduleInline extends Command
{
    protected $signature = 'schedule:run-inline';

    protected $description = 'Jalankan tugas terjadwal tanpa proc_open (untuk hosting yang mematikannya)';

    /** @var array<int, array{cron: string, command: string, args: array<string, mixed>}> */
    protected array $tugas = [
        ['cron' => '* * * * *', 'command' => 'attendance:parse-callbacks', 'args' => []],
        ['cron' => '*/15 * * * *', 'command' => 'attendance:compute', 'args' => []],
        ['cron' => '0 2 * * *', 'command' => 'attendance:sync', 'args' => ['--days' => 2]],
        ['cron' => '*/5 * * * *', 'command' => 'attendance:sync', 'args' => ['--days' => 1]],
        ['cron' => '0 3 * * *', 'command' => 'db:backup', 'args' => []],
        ['cron' => '0 6 * * *', 'command' => 'attendance:compute', 'args' => ['--days' => 2]],
        ['cron' => '* * * * *', 'command' => 'queue:work', 'args' => ['--stop-when-empty' => true, '--max-time' => 50, '--tries' => 3]],
    ];

    public function handle(): int
    {
        // Kunci lewat cache (database/file/redis), bukan Process — supaya
        // dua pemanggilan cron yang tumpang tindih (tick sebelumnya belum
        // selesai) tidak saling rebutan menjalankan command yang sama.
        $lock = Cache::lock('schedule-run-inline', 55);

        if (! $lock->get()) {
            $this->warn('Tick sebelumnya masih jalan, dilewati.');

            return self::SUCCESS;
        }

        try {
            $now = Carbon::now(config('attendance.timezone', 'Asia/Jakarta'));

            foreach ($this->tugas as $t) {
                if (! $this->cocokJadwal($t['cron'], $now)) {
                    continue;
                }

                $this->info("Menjalankan {$t['command']}...");

                try {
                    Artisan::call($t['command'], $t['args']);
                } catch (\Throwable $e) {
                    // Satu tugas gagal jangan sampai menghentikan tugas
                    // lain di tick yang sama.
                    $this->error("{$t['command']} gagal: {$e->getMessage()}");
                    report($e);
                }
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    protected function cocokJadwal(string $expr, Carbon $now): bool
    {
        [$menit, $jam, $hari, $bulan, $hariMinggu] = explode(' ', $expr);

        return $this->cocokField($menit, (int) $now->format('i'))
            && $this->cocokField($jam, (int) $now->format('H'))
            && $this->cocokField($hari, (int) $now->format('d'))
            && $this->cocokField($bulan, (int) $now->format('m'))
            && $this->cocokField($hariMinggu, (int) $now->format('w'));
    }

    protected function cocokField(string $field, int $nilai): bool
    {
        if ($field === '*') {
            return true;
        }

        if (str_starts_with($field, '*/')) {
            $langkah = (int) substr($field, 2);

            return $langkah > 0 && $nilai % $langkah === 0;
        }

        return (int) $field === $nilai;
    }
}
