<?php

namespace App\Enums;

enum RuleType: string
{
    case Late = 'late';
    case EarlyLeave = 'early_leave';
    case Overtime = 'overtime';
    case Absent = 'absent';
    case Bpjs = 'bpjs';

    public function label(): string
    {
        return match ($this) {
            self::Late => 'Potongan Terlambat',
            self::EarlyLeave => 'Potongan Pulang Cepat',
            self::Overtime => 'Tarif Lembur',
            self::Absent => 'Potongan Alpha',
            self::Bpjs => 'BPJS',
        };
    }

    /** Satuan yang dipakai tier-nya. */
    public function unit(): string
    {
        return match ($this) {
            self::Late, self::EarlyLeave => 'minute',
            self::Overtime => 'hour',
            self::Absent => 'day',
            self::Bpjs => 'percent',
        };
    }
}
