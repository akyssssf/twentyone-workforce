<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ListUsers extends Command
{
    protected $signature = 'user:list';

    protected $description = 'Tampilkan akun dashboard beserta perannya';

    public function handle(): int
    {
        $users = User::orderBy('name')->get();

        if ($users->isEmpty()) {
            $this->warn('Belum ada akun dashboard.');
            $this->line('Buat dengan <comment>php artisan user:add</comment>.');

            return self::SUCCESS;
        }

        $this->table(
            ['Nama', 'Email', 'Peran', 'Aktif', 'Dibuat'],
            $users->map(fn (User $u) => [
                $u->name,
                $u->email,
                $u->role->label(),
                $u->is_active ? 'ya' : 'tidak',
                $u->created_at?->format('Y-m-d') ?? '-',
            ])->all(),
        );

        if (User::where('role', 'admin')->where('is_active', true)->doesntExist()) {
            $this->newLine();
            $this->warn('Tidak ada admin aktif. Buat satu sebelum terkunci dari pengelolaan akun.');
        }

        return self::SUCCESS;
    }
}
