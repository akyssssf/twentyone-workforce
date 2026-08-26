<?php

namespace App\Console\Commands;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Support\DateInput;
use App\Support\Durasi;
use App\Support\OperationalDate;
use Illuminate\Console\Command;

/**
 * Daftar rekap dengan status tertentu pada satu rentang tanggal.
 *
 * Ada karena angka ringkasan di web ("1 alpha") tidak menyebutkan SIAPA dan
 * KAPAN, dan tanpa itu satu-satunya cara mencarinya adalah membuka rekap
 * bulanan lalu memindainya dengan mata. Yang sudah terjadi: sebuah status yang
 * sudah dibetulkan tetap dikira bermasalah, karena angka "1 alpha" di layar
 * ternyata milik orang lain di tanggal lain.
 *
 * Alpha adalah status yang tidak dibayar, jadi salah orang di sini berujung ke
 * uang.
 */
class ListAttendanceStatus extends Command
{
    protected $signature = 'attendance:daftar
                            {--status=alpha : Status yang dicari: alpha, hadir, izin, sakit, cuti, libur}
                            {--from= : Tanggal awal (YYYY-MM-DD), kosong berarti hari ini}
                            {--to= : Tanggal akhir, kosong berarti sama dengan --from}';

    protected $description = 'Daftar siapa saja yang berstatus tertentu pada satu rentang tanggal';

    public function handle(): int
    {
        $status = AttendanceStatus::tryFrom(strtolower(trim((string) $this->option('status'))));

        if ($status === null) {
            $this->error("Status '{$this->option('status')}' tidak dikenal.");
            $this->line('Yang tersedia: '.collect(AttendanceStatus::cases())->pluck('value')->implode(', '));

            return self::FAILURE;
        }

        $dari = $this->option('from')
            ? DateInput::parseOrFail((string) $this->option('from'), 'from')
            : OperationalDate::today();

        $sampai = $this->option('to')
            ? DateInput::parseOrFail((string) $this->option('to'), 'to')
            : $dari->copy();

        if ($sampai->lessThan($dari)) {
            $this->error('--to lebih awal dari --from.');

            return self::FAILURE;
        }

        $baris = Attendance::query()
            ->with(['employee', 'shift'])
            ->where('status', $status->value)

            // Batas atas eksplisit sampai akhir hari: work_date tersimpan
            // sebagai "Y-m-d 00:00:00", jadi whereBetween dengan string tanggal
            // pendek MEMBUANG tanggal terakhirnya (jebakan nomor 3).
            ->whereBetween('work_date', [
                $dari->copy()->startOfDay(),
                $sampai->copy()->endOfDay(),
            ])
            ->orderBy('work_date')
            ->get();

        $this->newLine();

        if ($baris->isEmpty()) {
            $this->info(sprintf('Tidak ada yang berstatus %s antara %s dan %s.',
                $status->label(), $dari->toDateString(), $sampai->toDateString()));

            return self::SUCCESS;
        }

        $this->line(sprintf('<comment>%d rekap berstatus %s (%s s/d %s):</comment>',
            $baris->count(), $status->label(), $dari->toDateString(), $sampai->toDateString()));
        $this->newLine();

        $this->table(
            ['Tanggal', 'Karyawan', 'PIN', 'Shift', 'Masuk', 'Telat', 'Koreksi'],
            $baris->map(fn (Attendance $a) => [
                $a->work_date->toDateString(),
                $a->employee?->name ?? '—',
                (string) ($a->employee?->pin_device ?? '—'),
                $a->shift?->name ?? '—',
                $a->check_in_at?->format('H:i:s') ?? '—',
                Durasi::menit((int) $a->late_minutes),
                $a->source_note ?? '—',
            ])->all(),
        );

        $this->newLine();
        $this->line('Kenapa satu baris berbunyi begitu:  <info>php artisan attendance:jelaskan PIN TANGGAL</info>');
        $this->newLine();

        return self::SUCCESS;
    }
}
