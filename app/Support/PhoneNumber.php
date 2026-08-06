<?php

namespace App\Support;

/**
 * Penyeragam nomor HP Indonesia.
 *
 * Orang menulis nomor dengan banyak gaya: 0812-3456-7890, +62 812 3456 7890,
 * 62812xxx, 812xxx. Disimpan apa adanya, laporan WhatsApp nanti akan gagal
 * kirim ke sebagian orang tanpa alasan yang jelas. Diseragamkan di titik masuk
 * jauh lebih murah daripada membersihkannya belakangan.
 *
 * Bentuk simpanan: 628123456789 (tanpa tanda plus, siap dipakai WhatsApp).
 */
class PhoneNumber
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        // 0812... -> 62812...
        if (str_starts_with($digits, '0')) {
            return '62'.ltrim($digits, '0');
        }

        if (str_starts_with($digits, '62')) {
            return $digits;
        }

        // 812... -> 62812...
        if (str_starts_with($digits, '8')) {
            return '62'.$digits;
        }

        // Nomor luar negeri atau bentuk tak dikenal dibiarkan apa adanya
        // daripada dirusak oleh tebakan.
        return $digits;
    }

    /**
     * Bentuk enak dibaca buat ditampilkan: 0812-3456-7890.
     */
    public static function forDisplay(?string $value): ?string
    {
        $normal = self::normalize($value);

        if ($normal === null) {
            return null;
        }

        if (! str_starts_with($normal, '62')) {
            return $normal;
        }

        $lokal = '0'.substr($normal, 2);

        return trim(chunk_split($lokal, 4, '-'), '-');
    }
}
