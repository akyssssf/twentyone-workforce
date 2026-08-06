<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;

/**
 * Aktifkan atau nonaktifkan karyawan.
 *
 * Sengaja tidak ada perintah hapus. Menghapus karyawan akan memutus rekap
 * lama miliknya, dan rekap itu bukti pembayaran gaji. Nonaktif sudah cukup:
 * dia berhenti dihitung mulai sekarang, tapi riwayatnya utuh.
 */
class ToggleEmployee extends Command
{
    protected $signature = 'employee:toggle
                            {pin : PIN karyawan di mesin}
                            {--aktif : Aktifkan (bawaannya menonaktifkan)}';

    protected $description = 'Aktifkan atau nonaktifkan karyawan';

    public function handle(): int
    {
        $pin = (string) $this->argument('pin');
        $employee = Employee::where('pin_device', $pin)->first();

        if ($employee === null) {
            $this->error("Tidak ada karyawan dengan PIN {$pin}.");

            return self::FAILURE;
        }

        $aktif = (bool) $this->option('aktif');

        if ($employee->is_active === $aktif) {
            $this->warn("{$employee->name} memang sudah ".($aktif ? 'aktif' : 'nonaktif').'.');

            return self::SUCCESS;
        }

        $employee->update(['is_active' => $aktif]);

        if ($aktif) {
            $this->info("{$employee->name} (PIN {$pin}) diaktifkan kembali.");
            $this->line('Mulai sekarang dia ikut dihitung lagi di rekap harian.');

            return self::SUCCESS;
        }

        $this->info("{$employee->name} (PIN {$pin}) dinonaktifkan.");
        $this->line('Dia berhenti muncul di rekap harian dan laporan bulanan mulai sekarang.');
        $this->line('Rekap lamanya tetap tersimpan utuh sebagai bukti.');
        $this->newLine();
        $this->line('Scan dari PIN ini tetap akan tersimpan di Aktivitas Scan,');
        $this->line('cuma tidak lagi dihitung jadi absensi.');

        return self::SUCCESS;
    }
}
