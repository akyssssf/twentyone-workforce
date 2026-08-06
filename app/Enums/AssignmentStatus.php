<?php

namespace App\Enums;

enum AssignmentStatus: string
{
    case Scheduled = 'scheduled';
    case Off = 'off';
    case Leave = 'leave';
    case Holiday = 'holiday';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Bertugas',
            self::Off => 'Libur',
            self::Leave => 'Cuti/Izin',
            self::Holiday => 'Tanggal Merah',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function isWorking(): bool
    {
        return $this === self::Scheduled;
    }
}
