<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Ganti kata sandi akun dashboard.
 *
 * Belum ada halaman ubah sandi di web, jadi ini satu-satunya jalan. Sandinya
 * selalu lewat prompt tersembunyi, tidak pernah lewat opsi command, supaya
 * tidak tertinggal di riwayat shell.
 */
class ChangeUserPassword extends Command
{
    protected $signature = 'user:password {email? : Email akun yang mau diganti}';

    protected $description = 'Ganti kata sandi akun dashboard';

    public function handle(): int
    {
        $email = $this->argument('email') ?: $this->ask('Email akun');

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("Tidak ada akun dengan email {$email}.");
            $this->line('Lihat daftarnya dengan: php artisan user:list');

            return self::FAILURE;
        }

        $password = $this->secret('Kata sandi baru (minimal 8 karakter)');

        if (strlen((string) $password) < 8) {
            $this->error('Kata sandi minimal 8 karakter.');

            return self::FAILURE;
        }

        if ($password !== $this->secret('Ulangi kata sandi baru')) {
            $this->error('Kata sandi tidak cocok.');

            return self::FAILURE;
        }

        // Di-hash otomatis oleh cast 'password' => 'hashed'.
        $user->update(['password' => $password]);

        $this->info("Kata sandi {$user->email} sudah diganti.");
        $this->line('Sesi yang sedang aktif tidak ikut terputus. Kalau perlu, keluar lalu masuk lagi.');

        return self::SUCCESS;
    }
}
