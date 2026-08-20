<?php

namespace App\Services\Attendance;

use App\Models\RosterAssignment;
use App\Models\Shift;
use Illuminate\Support\Carbon;

/**
 * Rentang waktu yang dianggap milik satu work_date untuk satu shift.
 *
 * Kelas ini ada karena "absensi tanggal 6" tidak sama dengan "scan yang
 * tanggalnya 6". Shift 2 berjalan 17:00 sampai 01:00, jadi scan pulangnya
 * jatuh di tanggal 7. Kalau pengelompokannya pakai tanggal kalender mentah,
 * jam pulang shift malam hilang setiap hari tanpa ada yang sadar.
 */
class WorkWindow
{
    public function __construct(
        public readonly Carbon $scheduledIn,
        public readonly Carbon $scheduledOut,
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {}

    /**
     * $assignment dipakai untuk jam khusus per tanggal — bos sesekali meminta
     * jam berbeda di satu hari tertentu, dan jam di master shift berlaku
     * global sehingga tidak bisa diubah cuma untuk sehari. Null berarti ikut
     * jam master seperti biasa.
     */
    public static function for(Shift $shift, Carbon $workDate, ?RosterAssignment $assignment = null): self
    {
        $timezone = config('attendance.timezone', 'Asia/Jakarta');
        $date = $workDate->copy()->setTimezone($timezone)->startOfDay();

        $mulai = $assignment?->start_time_override ?? $shift->start_time;
        $selesai = $assignment?->end_time_override ?? $shift->end_time;

        $scheduledIn = self::applyTime($date, $mulai);
        $shiftEnd = self::applyTime($date, $selesai);

        // Jam pulang yang tidak lebih besar dari jam masuk berarti shift ini
        // melewati tengah malam, jadi ujungnya digeser ke hari berikutnya.
        if ($shiftEnd->lessThanOrEqualTo($scheduledIn)) {
            $shiftEnd->addDay();
        }

        // Toleransi jendela diambil dari master shift, bukan dari config
        // global: shift malam yang berakhir 01:00 wajar punya toleransi
        // berbeda dari shift pagi. Config tetap jadi cadangan kalau kolomnya
        // belum terisi.
        $before = (int) ($shift->window_before_hours ?? config('attendance.window.before_shift_hours', 4));
        $after = (int) ($shift->window_after_hours ?? config('attendance.window.after_shift_hours', 4));

        return new self(
            scheduledIn: $scheduledIn,
            scheduledOut: $shiftEnd,
            start: $scheduledIn->copy()->subHours($before),
            end: $shiftEnd->copy()->addHours($after),
        );
    }

    public function contains(Carbon $moment): bool
    {
        return $moment->betweenIncluded($this->start, $this->end);
    }

    protected static function applyTime(Carbon $date, string $time): Carbon
    {
        // Kolom time bisa terbaca sebagai "09:00:00" atau "09:00".
        [$hour, $minute, $second] = array_pad(array_map('intval', explode(':', $time)), 3, 0);

        return $date->copy()->setTime($hour, $minute, $second);
    }
}
