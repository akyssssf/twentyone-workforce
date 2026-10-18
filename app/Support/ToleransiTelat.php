<?php

namespace App\Support;

/**
 * Satu-satunya tempat yang menentukan apakah sebuah keterlambatan masih
 * dalam toleransi.
 *
 * Toleransi 10 menit berarti sampai 10 menit 59 detik: orang yang datang
 * 10 menit 30 detik setelah jam masuk masih "di menit ke-10", walau menit
 * yang dibulatkan ke atas akan menuliskannya sebagai 11. Dulu pembandingnya
 * detik mentah (<= 600), jadi 10:01 saja sudah tertulis "11 menit" dan
 * dipotong — padahal yang melihat jamnya sendiri bilang masih menit ke-10.
 * Yang dipotong baru mulai 12 menit.
 *
 * Toleransi 0 tetap ketat: lewat satu detik pun dihitung (BR-04).
 *
 * Dipakai AttendanceComputer (menentukan late_minutes) dan PayrollGenerator
 * (membaca detik mentah). Dua tempat dengan aturan sendiri-sendiri pasti
 * lama-lama berbeda, dan beda di sini berarti slip tidak cocok dengan rekap.
 */
class ToleransiTelat
{
    public static function menit(): int
    {
        return max(0, Settings::int('attendance.late_tolerance_minutes'));
    }

    /** @param  int  $detik  selisih sebenarnya dari jam masuk terjadwal */
    public static function dimaafkan(int $detik): bool
    {
        if ($detik <= 0) {
            return true;
        }

        $toleransi = static::menit();

        return $toleransi > 0 && $detik < ($toleransi + 1) * 60;
    }
}
