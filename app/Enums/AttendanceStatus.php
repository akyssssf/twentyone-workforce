<?php

namespace App\Enums;

/**
 * Status kehadiran satu hari kerja.
 *
 * Hanya enam, bukan sembilan seperti daftar di brief, karena tiga sisanya
 * bukan status: "Terlambat" dan "Pulang Cepat" adalah angka menit yang bisa
 * terjadi bersamaan dengan status apa pun, dan "Lembur" adalah entitas
 * tersendiri yang punya approval. Seorang chef bisa Hadir, Terlambat, Pulang
 * Cepat, dan Lembur di hari yang sama - kalau semuanya dipaksa jadi satu kolom,
 * tiga fakta hilang dan ketiganya berdampak ke uang.
 */
enum AttendanceStatus: string
{
    case Hadir = 'hadir';
    case Alpha = 'alpha';
    case Izin = 'izin';
    case Sakit = 'sakit';
    case Cuti = 'cuti';
    case Libur = 'libur';

    public function label(): string
    {
        return match ($this) {
            self::Hadir => 'Hadir',
            self::Alpha => 'Alpha',
            self::Izin => 'Izin',
            self::Sakit => 'Sakit',
            self::Cuti => 'Cuti',
            self::Libur => 'Libur',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Hadir => 'emerald',
            self::Alpha => 'red',
            self::Izin => 'sky',
            self::Sakit => 'violet',
            self::Cuti => 'indigo',
            self::Libur => 'slate',
        };
    }

    /** Hari yang memang tidak dijadwalkan kerja. */
    public function isNonWorking(): bool
    {
        return in_array($this, [self::Libur, self::Cuti, self::Izin, self::Sakit], true);
    }

    /** Dibayar penuh? Alpha dan izin tidak. */
    public function isPaid(): bool
    {
        return $this !== self::Alpha && $this !== self::Izin;
    }
}
