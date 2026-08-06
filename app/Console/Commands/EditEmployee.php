<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Shift;
use App\Support\DateInput;
use App\Support\DayOfWeek;
use Illuminate\Console\Command;

/**
 * Ubah data karyawan yang sudah terdaftar.
 *
 * Dicari lewat PIN karena itu satu-satunya penghubung yang pasti antara
 * catatan kita dan mesin.
 */
class EditEmployee extends Command
{
    protected $signature = 'employee:edit
                            {pin : PIN karyawan di mesin}
                            {--name= : Ganti nama}
                            {--phone= : Ganti nomor HP}
                            {--shift= : Ganti shift}
                            {--salary= : Ganti gaji pokok}
                            {--joined= : Ganti tanggal bergabung (YYYY-MM-DD)}
                            {--off-days= : Libur mingguan, misal "minggu" atau "senin,kamis" atau "-" untuk kosongkan}';

    protected $description = 'Ubah data karyawan yang sudah terdaftar';

    public function handle(): int
    {
        $pin = (string) $this->argument('pin');
        $employee = Employee::where('pin_device', $pin)->first();

        if ($employee === null) {
            $this->error("Tidak ada karyawan dengan PIN {$pin}.");
            $this->line('Lihat daftarnya dengan: php artisan employee:list --all');

            return self::FAILURE;
        }

        $perubahan = [];

        if ($nama = $this->option('name')) {
            $perubahan['name'] = trim($nama);
        }

        if ($this->option('phone') !== null) {
            // Diseragamkan otomatis oleh mutator di model Employee.
            $perubahan['phone'] = $this->option('phone') ?: null;
        }

        if ($this->option('salary') !== null) {
            // Gaji tidak lagi kolom tunggal: perubahan gaji dicatat sebagai
            // baris baru berlaku mulai hari ini, supaya slip gaji bulan lalu
            // tetap memakai angka yang berlaku saat itu.
            $gajiBaru = (int) preg_replace('/\D+/', '', (string) $this->option('salary'));
        }

        if ($shiftOpsi = $this->option('shift')) {
            $shift = Shift::where('name', $shiftOpsi)->orWhere('id', (int) $shiftOpsi)->first();

            if ($shift === null) {
                $this->error("Shift \"{$shiftOpsi}\" tidak ada. Pilihan: ".Shift::pluck('name')->implode(', '));

                return self::FAILURE;
            }

            $perubahan['default_shift_id'] = $shift->id;
        }

        if ($joined = $this->option('joined')) {
            $tanggal = DateInput::parse($joined);

            if ($tanggal === null) {
                $this->error("Tanggal bergabung tidak sah: \"{$joined}\".");

                return self::FAILURE;
            }

            $perubahan['joined_at'] = $tanggal;
        }

        if ($this->option('off-days') !== null) {
            $masukan = trim((string) $this->option('off-days'));

            // "-" dipakai untuk mengosongkan, karena string kosong di baris
            // perintah sulit dibedakan dari opsi yang tidak diisi.
            $perubahan['preferred_off_days'] = $masukan === '-' ? [] : DayOfWeek::parse($masukan);
        }

        if ($perubahan === [] && ! isset($gajiBaru)) {
            $this->warn('Tidak ada yang diubah. Sebutkan minimal satu opsi.');
            $this->line('Contoh: php artisan employee:edit 1 --shift="Shift 2" --off-days=minggu');

            return self::SUCCESS;
        }

        $employee->update($perubahan);

        // Gaji ditutup-dan-buka-baru, bukan ditimpa: baris lama diberi tanggal
        // akhir, baris baru berlaku mulai hari ini. Slip gaji yang sudah
        // terbit tetap memakai angka yang berlaku saat itu.
        if (isset($gajiBaru)) {
            $komponen = \App\Models\SalaryComponent::where('code', 'gaji_pokok')->first();

            if ($komponen !== null) {
                $employee->salaries()
                    ->where('salary_component_id', $komponen->id)
                    ->whereNull('effective_to')
                    ->update(['effective_to' => now()->subDay()->toDateString()]);

                $employee->salaries()->create([
                    'salary_component_id' => $komponen->id,
                    'amount' => $gajiBaru,
                    'effective_from' => now()->toDateString(),
                ]);
            }
        }

        $employee->refresh()->load('defaultShift');

        $this->info("Tersimpan: {$employee->name} (PIN {$employee->pin_device}).");
        $this->table(['Kolom', 'Nilai sekarang'], [
            ['Nama', $employee->name],
            ['Shift preferensi', $employee->defaultShift?->name ?? '-'],
            ['Gaji pokok', 'Rp '.number_format($employee->baseSalaryOn(now()), 0, ',', '.')],
            ['Libur mingguan', DayOfWeek::daftar($employee->offDays())],
            ['Bergabung', $employee->joined_at?->toDateString() ?? '-'],
            ['Aktif', $employee->is_active ? 'ya' : 'tidak'],
        ]);

        if (array_key_exists('default_shift_id', $perubahan) || array_key_exists('preferred_off_days', $perubahan)) {
            $this->newLine();
            $this->line('Rekap lama tidak ikut berubah. Jalankan <comment>php artisan attendance:compute</comment>');
            $this->line('dengan rentang tanggal yang mau dihitung ulang kalau perlu.');
        }

        return self::SUCCESS;
    }
}
