<?php

namespace App\Support;

/**
 * Pengubah durasi jadi bentuk yang enak dibaca manusia.
 *
 * Ada di satu tempat karena angka yang sama muncul di dashboard, rekap, dan
 * Excel — dan menit mentah berhenti bisa dibaca begitu lewat satu jam:
 * "354 m" memaksa pembacanya membagi 60 di kepala, "5j 54m" tidak.
 */
class Durasi
{
    /** Kosong ditulis '—', bukan '0m', supaya baris yang bersih tetap sepi. */
    public const KOSONG = '—';

    public static function menit(int $menit): string
    {
        if ($menit <= 0) {
            return self::KOSONG;
        }

        $jam = intdiv($menit, 60);
        $sisa = $menit % 60;

        if ($jam === 0) {
            return "{$sisa}m";
        }

        // Jam bulat tidak perlu "0m" di belakangnya.
        return $sisa === 0 ? "{$jam}j" : "{$jam}j {$sisa}m";
    }

    /**
     * Sama seperti menit(), tapi masukannya detik.
     *
     * Detik cuma ditampilkan kalau durasinya belum sampai satu menit —
     * di atas itu detik tidak menambah apa pun selain kebisingan.
     */
    public static function detik(int $detik): string
    {
        if ($detik <= 0) {
            return self::KOSONG;
        }

        if ($detik < 60) {
            return "{$detik}d";
        }

        return self::menit(intdiv($detik, 60));
    }
}
