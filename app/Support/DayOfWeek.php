<?php

namespace App\Support;

/**
 * Penerjemah nomor hari ala Carbon (0=Minggu ... 6=Sabtu) ke nama Indonesia,
 * dan sebaliknya.
 *
 * Ada di satu tempat supaya command, tampilan, dan laporan tidak masing-masing
 * punya daftar nama hari sendiri yang bisa berbeda urutannya.
 */
class DayOfWeek
{
    /** @var array<int, string> */
    public const NAMA = [
        0 => 'Minggu',
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
    ];

    public static function nama(int $hari): string
    {
        return self::NAMA[$hari] ?? (string) $hari;
    }

    /**
     * @param  array<int, int>  $hari
     */
    public static function daftar(array $hari): string
    {
        if ($hari === []) {
            return 'tidak ada';
        }

        sort($hari);

        return implode(', ', array_map(self::nama(...), $hari));
    }

    /**
     * Baca masukan bebas seperti "senin,jumat" atau "1,5" jadi array nomor
     * hari. Nilai yang tidak dikenali dibuang, bukan bikin proses gagal.
     *
     * @return array<int, int>
     */
    public static function parse(?string $masukan): array
    {
        if ($masukan === null || trim($masukan) === '') {
            return [];
        }

        $hasil = [];

        foreach (preg_split('/[,\s]+/', trim($masukan)) ?: [] as $bagian) {
            $bagian = trim($bagian);

            if ($bagian === '') {
                continue;
            }

            if (ctype_digit($bagian)) {
                $nomor = (int) $bagian;

                if ($nomor >= 0 && $nomor <= 6) {
                    $hasil[] = $nomor;
                }

                continue;
            }

            foreach (self::NAMA as $nomor => $nama) {
                if (strcasecmp($nama, $bagian) === 0) {
                    $hasil[] = $nomor;

                    break;
                }
            }
        }

        $hasil = array_values(array_unique($hasil));
        sort($hasil);

        return $hasil;
    }
}
