<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use App\Support\SandiAcak;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Buatkan akun login untuk karyawan yang belum punya.
 *
 * Sebelum ini tidak ada jalur mana pun yang bisa MEMBUAT akun karyawan: panel
 * admin bisa mengganti nama panggilan dan mengatur ulang sandi, tapi keduanya
 * berhenti dengan "belum punya akun login", dan `user:add` berbasis email serta
 * tidak menautkan akunnya ke karyawan. Jadi karyawan baru tidak pernah bisa
 * masuk sampai ada yang menyentuh database langsung.
 *
 * Sandinya acak dan ditampilkan SEKALI. Yang tersimpan cuma hash-nya, jadi
 * setelah layar ini tertutup tidak ada seorang pun yang bisa melihatnya lagi —
 * termasuk admin. Akunnya ditandai wajib ganti sandi saat login pertama.
 */
class CreateEmployeeAccount extends Command
{
    protected $signature = 'employee:akun
                            {pin : PIN karyawan di mesin}
                            {--username= : Nama panggilan untuk login, huruf kecil dan angka saja}
                            {--admin : Buat sebagai admin, bukan karyawan}';

    protected $description = 'Buatkan akun login untuk karyawan yang belum punya';

    public function handle(): int
    {
        $employee = Employee::where('pin_device', (string) $this->argument('pin'))->first();

        if ($employee === null) {
            $this->error("Tidak ada karyawan dengan PIN {$this->argument('pin')}.");

            return self::FAILURE;
        }

        if ($employee->user !== null) {
            $this->error("{$employee->name} sudah punya akun (nama panggilan \"{$employee->user->username}\").");
            $this->line('Untuk mengatur ulang sandinya, pakai halaman Karyawan → Detail di panel admin.');

            return self::FAILURE;
        }

        $username = strtolower(trim((string) ($this->option('username') ?: $this->ask('Nama panggilan untuk login'))));

        if (! preg_match('/^[a-z0-9]{3,32}$/', $username)) {
            // Aturan yang sama dengan panel admin: nama panggilan berspasi atau
            // berhuruf besar akan gagal diketik di layar ponsel.
            $this->error('Nama panggilan hanya boleh huruf kecil dan angka, 3 sampai 32 karakter.');

            return self::FAILURE;
        }

        if (User::where('username', $username)->exists()) {
            $this->error("Nama panggilan \"{$username}\" sudah dipakai orang lain.");

            return self::FAILURE;
        }

        $sandi = SandiAcak::buat();

        $user = User::create([
            'username' => $username,
            'name' => $employee->name,
            'email' => $this->emailUnik($employee),
            'password' => $sandi,
            'role' => $this->option('admin') ? UserRole::Admin : UserRole::Karyawan,
            'is_active' => true,
            'employee_id' => $employee->id,

            // Sandi acak buatan admin bukan rahasia milik orangnya sampai dia
            // menggantinya sendiri.
            'must_change_password' => true,
        ]);

        $this->newLine();
        $this->info("Akun dibuat untuk {$employee->name} ({$user->role->label()}).");
        $this->newLine();
        $this->line('  Nama panggilan : <comment>'.$user->username.'</comment>');
        $this->line('  Kata sandi     : <comment>'.$sandi.'</comment>');
        $this->newLine();
        $this->warn('Catat sandinya SEKARANG — yang tersimpan cuma hash-nya, tidak bisa dilihat lagi.');
        $this->line('Dia akan diminta menggantinya sendiri saat login pertama.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Email wajib terisi dan unik di tabel users, padahal login memakai nama
     * panggilan dan tidak ada satu pun email yang benar-benar dikirim —
     * pemberitahuan lewat WhatsApp. Mengikuti pola akun yang sudah ada
     * (nama@kafe.test); .test adalah TLD cadangan yang dijamin tidak pernah
     * resolve, jadi tidak mungkin ada surat nyasar ke alamat orang lain.
     */
    protected function emailUnik(Employee $employee): string
    {
        $dasar = Str::slug($employee->name, '.') ?: 'karyawan';
        $email = "{$dasar}@kafe.test";
        $i = 2;

        while (User::where('email', $email)->exists()) {
            $email = "{$dasar}{$i}@kafe.test";
            $i++;
        }

        return $email;
    }
}
