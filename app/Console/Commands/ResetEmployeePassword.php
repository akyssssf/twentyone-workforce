<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Support\SandiAcak;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Atur ulang sandi karyawan yang lupa, dari terminal.
 *
 * Jalurnya sama persis dengan tombol "Atur ulang sandi" di panel admin: sandi
 * acak, ditampilkan SEKALI, dan orangnya wajib menggantinya saat login pertama.
 * Yang tersimpan cuma hash-nya — setelah layar ini tertutup tidak ada seorang
 * pun yang bisa melihatnya lagi, termasuk admin.
 *
 * Sandi tidak pernah diketik oleh admin. Sandi yang dipilih admin itu rahasia
 * yang diketahui dua orang, dan yang seperti itu bukan rahasia.
 */
class ResetEmployeePassword extends Command
{
    protected $signature = 'employee:reset-sandi
                            {pin : PIN karyawan di mesin}';

    protected $description = 'Atur ulang sandi karyawan yang lupa — sandi acak sekali tampil';

    public function handle(): int
    {
        $employee = Employee::where('pin_device', (string) $this->argument('pin'))->first();

        if ($employee === null) {
            $this->error("Tidak ada karyawan dengan PIN {$this->argument('pin')}.");

            return self::FAILURE;
        }

        $akun = $employee->user;

        if ($akun === null) {
            $this->error("{$employee->name} belum punya akun login.");
            $this->line('Buatkan dulu: <info>php artisan employee:akun '.$employee->pin_device.' --username=...</info>');

            return self::FAILURE;
        }

        $sandi = SandiAcak::buat();

        $akun->forceFill([
            'password' => Hash::make($sandi),
            'must_change_password' => true,
        ])->save();

        $this->newLine();
        $this->info("Sandi {$employee->name} diatur ulang.");
        $this->newLine();
        $this->line('  Nama panggilan : <comment>'.$akun->username.'</comment>');
        $this->line('  Sandi baru     : <comment>'.$sandi.'</comment>');
        $this->newLine();
        $this->warn('Bacakan SEKARANG — yang tersimpan cuma hash-nya, tidak bisa dilihat lagi.');
        $this->line('Dia akan diminta menggantinya sendiri saat login pertama.');
        $this->newLine();

        return self::SUCCESS;
    }
}
