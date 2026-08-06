<?php

namespace App\Enums;

/**
 * Jenis pengajuan. Semuanya lewat satu tabel induk `requests`, dengan tabel
 * detail sendiri-sendiri.
 *
 * Cuti, Izin, dan Sakit tidak jadi tiga jenis terpisah di sini - ketiganya
 * `leave` dengan leave_type berbeda, karena alur approval dan bentuk datanya
 * identik. Yang membedakan cuma apakah dibayar dan apakah memotong kuota.
 */
enum RequestType: string
{
    case Leave = 'leave';
    case Overtime = 'overtime';
    case Swap = 'swap';
    case Correction = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::Leave => 'Cuti / Izin / Sakit',
            self::Overtime => 'Lembur',
            self::Swap => 'Tukar Shift',
            self::Correction => 'Koreksi Absensi',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Leave => 'Cuti',
            self::Overtime => 'Lembur',
            self::Swap => 'Tukar Shift',
            self::Correction => 'Koreksi',
        };
    }

    /** Tukar shift butuh rekan menerima dulu sebelum sampai ke manager. */
    public function needsPeerApproval(): bool
    {
        return $this === self::Swap;
    }
}
