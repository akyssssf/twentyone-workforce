<?php

namespace App\Enums;

/**
 * Siklus periode penggajian.
 *
 * `locked` adalah titik tidak bisa kembali dalam pemakaian normal: absensi,
 * roster, dan pengajuan di rentang tanggalnya menolak semua perubahan. Koreksi
 * yang datang setelah ini dibayar di periode berikutnya sebagai penyesuaian,
 * bukan dengan membuka kunci.
 */
enum PayrollStatus: string
{
    case Open = 'open';
    case Generating = 'generating';
    case Generated = 'generated';
    case Approved = 'approved';
    case Locked = 'locked';
    case Reopened = 'reopened';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Berjalan',
            self::Generating => 'Sedang Dihitung',
            self::Generated => 'Sudah Dihitung',
            self::Approved => 'Disetujui',
            self::Locked => 'Terkunci',
            self::Reopened => 'Dibuka Ulang',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Locked => 'slate',
            self::Approved => 'emerald',
            self::Generated => 'sky',
            self::Generating => 'amber',
            self::Reopened => 'orange',
            self::Open => 'slate',
        };
    }

    public function isLocked(): bool
    {
        return $this === self::Locked;
    }

    public function canGenerate(): bool
    {
        return in_array($this, [self::Open, self::Generated, self::Reopened], true);
    }
}
