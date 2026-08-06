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
    case Admin = 'admin';
    case Karyawan = 'karyawan';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Karyawan => 'Karyawan',
        };
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    public function isManagement(): bool
    {
        return $this === self::Admin;
    }

    public function isEmployee(): bool
    {
        return $this === self::Karyawan;
    }

    public function isOwner(): bool
    {
        return $this === self::Admin;
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
