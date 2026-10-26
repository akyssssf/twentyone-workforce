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

    /**
     * Hari kerja bersama (General Cleaning): jamnya dari SCAN, bukan dari
     * "menyambung setelah jam pulang shift". Tidak muncul di formulir lembur
     * biasa; dibuat lewat lembur:kode-umum dan dihitung lewat lembur:umum.
     */
    case GeneralCleaning = 'general_cleaning';

    public function label(): string
    {
        return match ($this) {
            self::Pengganti => 'Menggantikan rekan',
            self::LiveMusic => 'Live music',
            self::Nobar => 'Nobar',
            self::Acara => 'Acara lain',
            self::GeneralCleaning => 'General Cleaning',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pengganti => 'slate',
            self::LiveMusic => 'violet',
            self::Nobar => 'sky',
            self::Acara => 'indigo',
            self::GeneralCleaning => 'amber',
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

    /** Jamnya diambil dari scan dan diisi lembur:umum — perhitungan otomatis jangan menyentuhnya. */
    public function hitungDariScan(): bool
    {
        return $this === self::GeneralCleaning;
    }

    /**
     * Pilihan untuk formulir dan penugasan biasa. General Cleaning sengaja
     * tidak ada di sini: lembur biasa menyambung setelah jam pulang shift.
     *
     * @return list<self>
     */
    public static function untukFormulir(): array
    {
        return array_values(array_filter(self::cases(), fn (self $o) => ! $o->hitungDariScan()));
    }
}
