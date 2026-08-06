<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Pembuat akun dashboard.
 *
 * Kata sandi selalu diminta lewat prompt tersembunyi, tidak pernah lewat opsi
 * command, supaya tidak tertinggal di riwayat shell.
 */
class AddUser extends Command
{
    protected $signature = 'user:add
                            {--name= : Nama pengguna}
                            {--email= : Alamat email untuk login}
                            {--role= : admin atau karyawan}';

    protected $description = 'Buat akun dashboard untuk admin atau karyawan';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Nama');
        $email = $this->option('email') ?: $this->ask('Email');

        $role = $this->option('role') ?: $this->choice(
            'Peran',
            array_keys(UserRole::options()),
            'admin',
        );

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'role' => $role],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'unique:users,email'],
                'role' => ['required', 'in:admin,karyawan'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $pesan) {
                $this->error($pesan);
            }

            return self::FAILURE;
        }

        $password = $this->secret('Kata sandi (minimal 8 karakter)');

        if (strlen((string) $password) < 8) {
            $this->error('Kata sandi minimal 8 karakter.');

            return self::FAILURE;
        }

        if ($password !== $this->secret('Ulangi kata sandi')) {
            $this->error('Kata sandi tidak cocok.');

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            // Di-hash otomatis oleh cast 'password' => 'hashed'.
            'password' => $password,
            'role' => $role,
            'is_active' => true,
        ]);

        $this->info("Akun dibuat: {$user->name} <{$user->email}> sebagai {$user->role->label()}.");

        return self::SUCCESS;
    }
}
