<?php

namespace App\Enums;

/**
 * Peran pengguna dashboard.
 *
 * Ini hak akses WEB, terpisah dari privilege di mesin Fingerspot (1=karyawan,
 * 2=admin) yang mengatur siapa boleh membuka menu admin di alatnya. Karyawan
 * biasa tidak punya akun di sini sama sekali.
 */
enum UserRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Karyawan = 'karyawan';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Manager => 'Manajer',
            self::Karyawan => 'Karyawan',
        };
    }

    /**
     * Brief meminta hanya dua peran: Manager dan Karyawan. Owner tetap ada di
     * database sebagai manager-plus (boleh mengelola akun dan mengubah gaji),
     * tapi di mata pengguna keduanya sama-sama "Manajer". Biaya menyimpan peran
     * ketiga hampir nol; biaya menghapusnya lalu membutuhkannya lagi tidak nol.
     */
    public function isManagement(): bool
    {
        return $this !== self::Karyawan;
    }

    public function isEmployee(): bool
    {
        return $this === self::Karyawan;
    }

    /**
     * Owner memegang hal-hal yang berakibat permanen atau menyangkut uang:
     * mengelola akun dashboard, mengubah gaji pokok, menonaktifkan karyawan.
     */
    public function isOwner(): bool
    {
        return $this === self::Owner;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $role) => $carry + [$role->value => $role->label()],
            [],
        );
    }
}
