<?php

namespace App\Enums;

/**
 * Untuk apa lemburnya.
 *
 * Awalnya lembur selalu dianggap menutup posisi orang lain, sehingga pengganti
 * wajib ditunjuk. Kenyataannya tidak selalu begitu: live music, nobar, dan
 * acara sejenis menambah tenaga untuk acaranya sendiri, bukan menggantikan
 * siapa pun — dan memaksa menunjuk pengganti di situ membuat admin mengarang
 * nama supaya formnya mau tersimpan.
 */
enum OvertimeOccasion: string
{
    case Pengganti = 'pengganti';
    case LiveMusic = 'live_music';
    case Nobar = 'nobar';
    case Acara = 'acara';

    public function label(): string
    {
        return match ($this) {
            self::Pengganti => 'Menggantikan rekan',
            self::LiveMusic => 'Live music',
            self::Nobar => 'Nobar',
            self::Acara => 'Acara lain',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pengganti => 'slate',
            self::LiveMusic => 'violet',
            self::Nobar => 'sky',
            self::Acara => 'indigo',
        };
    }

    /**
     * Cuma penggantian orang yang wajib menyebut siapa yang ditutup posisinya.
     * Acara berdiri sendiri — tidak ada posisi yang ditinggalkan.
     */
    public function butuhPengganti(): bool
    {
        return $this === self::Pengganti;
    }
}
