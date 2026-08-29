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
                            {--status=alpha : Status yang dicari, atau "semua" untuk semua status}
                            {--telat-min= : Hanya yang telatnya minimal sekian MENIT}
                            {--from= : Tanggal awal (YYYY-MM-DD), kosong berarti hari ini}
                            {--to= : Tanggal akhir, kosong berarti sama dengan --from}';

    protected $description = 'Daftar siapa saja yang berstatus tertentu pada satu rentang tanggal';

    public function handle(): int
    {
        $kodeStatus = strtolower(trim((string) $this->option('status')));

        // "semua" ada supaya penyaring telat bisa dipakai lintas status: telat
        // yang janggal tidak selalu berstatus alpha, dan justru yang berstatus
        // hadir-lah yang paling gampang lolos dari perhatian.
        $status = in_array($kodeStatus, ['semua', 'all', ''], true)
            ? null
            : AttendanceStatus::tryFrom($kodeStatus);

        if ($status === null && ! in_array($kodeStatus, ['semua', 'all', ''], true)) {
            $this->error("Status '{$this->option('status')}' tidak dikenal.");
            $this->line('Yang tersedia: '.collect(AttendanceStatus::cases())->pluck('value')->implode(', ').', atau semua');

            return self::FAILURE;
        }

        $telatMin = $this->option('telat-min');

        if ($telatMin !== null && ! ctype_digit((string) $telatMin)) {
            $this->error('--telat-min harus angka menit, mis. --telat-min=120 untuk 2 jam.');

            return self::FAILURE;
        }

        $telatMin = $telatMin === null ? null : (int) $telatMin;

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
            ->when($status !== null, fn ($q) => $q->where('status', $status->value))
            ->when($telatMin !== null, fn ($q) => $q->where('late_minutes', '>=', $telatMin))

            // Batas atas eksplisit sampai akhir hari: work_date tersimpan
            // sebagai "Y-m-d 00:00:00", jadi whereBetween dengan string tanggal
            // pendek MEMBUANG tanggal terakhirnya (jebakan nomor 3).
            ->whereBetween('work_date', [
                $dari->copy()->startOfDay(),
                $sampai->copy()->endOfDay(),
            ])
            // Kalau yang dicari telat, yang paling parah harus di atas — itu
            // yang paling mungkin bukan telat sungguhan.
            ->when($telatMin !== null, fn ($q) => $q->orderByDesc('late_minutes'))
            ->orderBy('work_date')
            ->get();

        $this->newLine();

        $sebutan = trim(($status?->label() ?? 'semua status')
            .($telatMin !== null ? " dengan telat ≥ {$telatMin} menit" : ''));

        if ($baris->isEmpty()) {
            $this->info(sprintf('Tidak ada rekap %s antara %s dan %s.',
                $sebutan, $dari->toDateString(), $sampai->toDateString()));

            return self::SUCCESS;
        }

        $this->line(sprintf('<comment>%d rekap %s (%s s/d %s):</comment>',
            $baris->count(), $sebutan, $dari->toDateString(), $sampai->toDateString()));
        $this->newLine();

        $this->table(
            ['Tanggal', 'Karyawan', 'PIN', 'Shift', 'Jadwal', 'Masuk', 'Telat', 'Status'],
            $baris->map(fn (Attendance $a) => [
                $a->work_date->toDateString(),
                $a->employee?->name ?? '—',
                (string) ($a->employee?->pin_device ?? '—'),
                $a->shift?->name ?? '—',
                $a->scheduled_in?->format('H:i') ?? '—',
                $a->check_in_at?->format('H:i:s') ?? '—',
                Durasi::menit((int) $a->late_minutes),
                $a->status->label(),
            ])->all(),
        );

        $this->newLine();
        $this->line('Kenapa satu baris berbunyi begitu:  <info>php artisan attendance:jelaskan PIN TANGGAL</info>');
        $this->newLine();

        return self::SUCCESS;
    }
}
