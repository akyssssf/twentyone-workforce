<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Support\DayOfWeek;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;

class ListEmployees extends Command
{
    protected $signature = 'employee:list {--all : Ikutkan karyawan nonaktif}';

    protected $description = 'Tampilkan karyawan beserta pemetaan PIN mesinnya';

    public function handle(): int
    {
        $employees = Employee::with('defaultShift')
            ->when(! $this->option('all'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        if ($employees->isEmpty()) {
            $this->warn('Belum ada karyawan terdaftar.');
            $this->line('Tambahkan dengan <comment>php artisan employee:add</comment>');
            $this->line('atau impor massal dengan <comment>php artisan employee:import berkas.csv</comment>.');

            return self::SUCCESS;
        }

        $this->table(
            ['PIN', 'Nama', 'Shift', 'Masuk', 'Libur mingguan', 'HP', 'Gaji pokok', 'Aktif'],
            $employees->map(fn (Employee $e) => [
                $e->pin_device,
                $e->name,
                $e->defaultShift?->name ?? '<error>belum diatur</error>',
                $e->defaultShift?->start_time ?? '-',
                DayOfWeek::daftar($e->offDays()),
                PhoneNumber::forDisplay($e->phone) ?? '-',
                ($g = $e->baseSalaryOn(now())) > 0 ? 'Rp '.number_format($g, 0, ',', '.') : '-',
                $e->is_active ? 'ya' : 'tidak',
            ])->all(),
        );

        // Tanpa libur mingguan, setiap hari istirahat mereka akan terhitung
        // alpha dan mengotori laporan gajian.
        $tanpaLibur = $employees->filter(fn (Employee $e) => $e->offDays() === []);

        if ($tanpaLibur->isNotEmpty()) {
            $this->newLine();
            $this->warn('Belum punya libur mingguan: '.$tanpaLibur->pluck('name')->implode(', '));
            $this->line('Hari istirahat mereka akan terhitung alpha. Atur dengan:');
            $this->line('  <comment>php artisan employee:edit PIN --off-days=minggu</comment>');
        }

        // Karyawan tanpa shift tidak akan pernah muncul di rekap, dan itu
        // gampang lolos dari perhatian.
        $tanpaShift = $employees->whereNull('default_shift_id');

        if ($tanpaShift->isNotEmpty()) {
            $this->newLine();
            $this->warn('Belum punya shift, jadi tidak akan masuk rekap: '.$tanpaShift->pluck('name')->implode(', '));
        }

        return self::SUCCESS;
    }
}
