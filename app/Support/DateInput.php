<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Pembaca tanggal "YYYY-MM-DD" yang benar-benar ketat.
 *
 * Ada karena Carbon::createFromFormat() jauh lebih longgar daripada dugaan:
 * dia menerima "13-45-99" lalu menggulungnya diam-diam jadi 0016-12-08, dan
 * menerima "2026-02-31" jadi 3 Maret. Tidak ada exception, tidak ada tanda
 * apa pun. Di halaman laporan, tanggal hasil gulungan itu menyaring habis
 * seluruh data dan terlihat persis seperti "datanya hilang".
 *
 * Penjagaannya: hasil parse diformat balik dan dibandingkan dengan string
 * asal. Kalau tidak sama persis, masukannya memang tidak sah.
 */
class DateInput
{
    /**
     * Kembalikan null kalau masukannya kosong atau bukan tanggal yang sah.
     */
    public static function parse(mixed $value, ?string $timezone = null): ?Carbon
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $timezone ??= config('attendance.timezone', 'Asia/Jakarta');

        try {
            $tanggal = Carbon::createFromFormat('Y-m-d', $value, $timezone);
        } catch (\Exception) {
            return null;
        }

        // Inti penjagaannya: tanggal hasil gulungan tidak akan cocok lagi
        // dengan string aslinya.
        if ($tanggal->format('Y-m-d') !== $value) {
            return null;
        }

        return $tanggal->startOfDay();
    }

    /**
     * Sama seperti parse(), tapi melempar exception alih-alih mengembalikan
     * null. Dipakai command yang memang harus berhenti kalau argumennya salah.
     */
    public static function parseOrFail(mixed $value, string $label): Carbon
    {
        $tanggal = self::parse($value);

        if ($tanggal === null) {
            $terbaca = is_scalar($value) ? (string) $value : gettype($value);

            throw new \InvalidArgumentException(
                "Nilai {$label} bukan tanggal YYYY-MM-DD yang sah: \"{$terbaca}\"."
            );
        }

        return $tanggal;
    }
}
