<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * "Hari ini" versi operasional kafe, bukan versi kalender.
 *
 * Shift malam baru benar-benar tutup jam 01:00, dan jendela absensinya
 * (window_after_hours) baru benar-benar berakhir jam 05:00 — attendance:compute
 * harian sengaja dijadwalkan jam 06:00 justru karena ini (lihat
 * routes/console.php). Tapi dashboard & beranda karyawan yang pakai
 * Carbon::today() polos pindah ke tanggal baru persis tengah malam, jauh
 * sebelum shift malam kelar. Akibatnya jam 1-5 pagi, "Hari Ini" menampilkan
 * tanggal baru yang masih kosong sama sekali — padahal yang orang mau lihat
 * adalah shift malam kemarin yang baru saja atau justru sedang pulang.
 *
 * Kelas ini menggeser ambang gantinya ke jam cutover (default 06:00, sama
 * dengan konvensi cron yang sudah ada), bukan tengah malam.
 */
class OperationalDate
{
    public static function today(?string $timezone = null): Carbon
    {
        $timezone ??= config('attendance.timezone', 'Asia/Jakarta');
        $sekarang = Carbon::now($timezone);
        $cutover = (int) config('attendance.dashboard_cutover_hour', 6);

        return $sekarang->hour < $cutover
            ? $sekarang->copy()->subDay()->startOfDay()
            : $sekarang->copy()->startOfDay();
    }
}
