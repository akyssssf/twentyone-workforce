<?php

namespace App\Enums;

/**
 * Draft tidak terlihat karyawan. Jadwal setengah jadi yang bocor menimbulkan
 * kegaduhan yang tidak perlu.
 */
enum RosterStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Locked = 'locked';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Published => 'Terbit',
            self::Locked => 'Terkunci',
        };
    }

    public function isEditable(): bool
    {
        return $this !== self::Locked;
    }
}
