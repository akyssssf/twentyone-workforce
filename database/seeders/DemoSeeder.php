<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\AttendanceLog;
use App\Models\Branch;
use App\Models\Division;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\EmployeeSalary;
use App\Models\SalaryComponent;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder data real dari mesin Fingerspot (developer.fingerspot.io).
 *
 * Menghapus data karyawan dummy lama, lalu membuat data karyawan & akun pengguna
 * (peran: Admin dan Karyawan) yang cocok dengan PIN fisik mesin Fingerspot.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Bersihkan data karyawan & user lama
        DB::statement('PRAGMA foreign_keys = OFF;');
        User::withTrashed()->forceDelete();
        EmployeeDevice::withTrashed()->forceDelete();
        EmployeeSalary::query()->delete();
        Employee::withTrashed()->forceDelete();
        DB::statement('PRAGMA foreign_keys = ON;');

        $branch = Branch::first();
        $pagi = Shift::where('code', 'pagi')->first();
        $malam = Shift::where('code', 'malam')->first();
        $divisions = Division::pluck('id', 'code');
        $gajiPokok = SalaryComponent::where('code', 'gaji_pokok')->first();

        // Data real PIN & nama dari mesin Fingerspot (GQ5179086)
        // Nama diambil langsung dari Person Management di developer.fingerspot.io
        $realPinMap = [
            '1'  => ['name' => '21 Zafan',                       'divisi' => 'chef',     'shift' => $pagi,  'gaji' => 4_500_000, 'libur' => [0]],
            '2'  => ['name' => 'Dava Erik Prasetiyo',            'divisi' => 'chef',     'shift' => $pagi,  'gaji' => 4_500_000, 'libur' => [1]],
            '3'  => ['name' => 'Farrel Daffa',                   'divisi' => 'chef',     'shift' => $malam, 'gaji' => 4_800_000, 'libur' => [2]],
            '4'  => ['name' => 'Giat Firhan Sigit',              'divisi' => 'barista',  'shift' => $pagi,  'gaji' => 4_000_000, 'libur' => [3]],
            '5'  => ['name' => 'Alvano Yuri Rafa Islama Andi',   'divisi' => 'barista',  'shift' => $malam, 'gaji' => 4_000_000, 'libur' => [4]],
            '6'  => ['name' => 'Muhammad Julian Ikhlusul Amal',  'divisi' => 'kasir',    'shift' => $pagi,  'gaji' => 3_800_000, 'libur' => [5]],
            '7'  => ['name' => 'Zulfiki Al Khafid',              'divisi' => 'kasir',    'shift' => $malam, 'gaji' => 3_800_000, 'libur' => [0]],
            '8'  => ['name' => 'Nurdiansyah',                    'divisi' => 'waiter',   'shift' => $pagi,  'gaji' => 3_500_000, 'libur' => [1]],
            '9'  => ['name' => 'Rifqi Ubaidillah',               'divisi' => 'waiter',   'shift' => $malam, 'gaji' => 3_500_000, 'libur' => [2]],
            '10' => ['name' => 'Abdila Riansyah',                'divisi' => 'waiter',   'shift' => $malam, 'gaji' => 3_500_000, 'libur' => [3]],
            '11' => ['name' => 'Fikri Imamy',                    'divisi' => 'waiter',   'shift' => $pagi,  'gaji' => 3_500_000, 'libur' => [4]],
            '12' => ['name' => 'Muhammad Nasdana Faza',          'divisi' => 'cleaning', 'shift' => $pagi,  'gaji' => 3_200_000, 'libur' => [5]],
            '13' => ['name' => '21 Bryan',                       'divisi' => 'cleaning', 'shift' => $pagi,  'gaji' => 3_200_000, 'libur' => [6]],
            '14' => ['name' => 'Jihan Yuni Ariszqi',             'divisi' => 'barista',  'shift' => $malam, 'gaji' => 4_000_000, 'libur' => [0]],
            '15' => ['name' => 'Setia Pribadi Bogel',            'divisi' => 'waiter',   'shift' => $pagi,  'gaji' => 3_500_000, 'libur' => [1]],
        ];

        $cloudId = config('fingerspot.cloud_id') ?: 'GQ5179086';
        $index = 0;

        foreach ($realPinMap as $pin => $spec) {
            $index++;
            $nama = $spec['name'];
            $divisi = $spec['divisi'];
            $shift = $spec['shift'];
            $gaji = $spec['gaji'];
            $libur = $spec['libur'];

            $employee = Employee::create([
                'branch_id' => $branch->id,
                'employee_no' => sprintf('EMP-%03d', $index),
                'pin_device' => (string) $pin,
                'name' => $nama,
                'email' => $this->emailFor($nama),
                'default_shift_id' => $shift->id,
                'preferred_off_days' => $libur,
                'employment_status' => 'active',
                'is_active' => true,
                'joined_at' => Carbon::parse('2024-01-01'),
            ]);

            $employee->devices()->create([
                'cloud_id' => $cloudId,
                'pin' => (string) $pin,
                'valid_from' => '2024-01-01',
                'valid_to' => null,
            ]);

            $employee->divisions()->syncWithoutDetaching([
                $divisions[$divisi] => ['is_primary' => true, 'competency_level' => 3],
            ]);

            EmployeeSalary::create([
                'employee_id' => $employee->id,
                'salary_component_id' => $gajiPokok->id,
                'effective_from' => '2024-01-01',
                'amount' => $gaji,
            ]);

            // Peran 2: Karyawan
            User::create([
                'employee_id' => $employee->id,
                'name' => $nama,
                'email' => $this->emailFor($nama),
                'password' => Hash::make('karyawan123'),
                'role' => UserRole::Karyawan,
                'is_active' => true,
                'must_change_password' => true,
            ]);
        }

        // Peran 1: Admin
        User::create([
            'name' => 'Admin Kafe',
            'email' => 'admin@kafe.test',
            'password' => Hash::make('admin123'),
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        // Hubungkan ulang semua log absensi ke karyawan baru
        foreach (AttendanceLog::whereNull('employee_id')->get() as $log) {
            $empId = EmployeeDevice::resolveEmployeeId($log->pin, $log->scanned_at, $log->cloud_id)
                ?? EmployeeDevice::resolveEmployeeId($log->pin, $log->scanned_at);

            if ($empId !== null) {
                $log->update(['employee_id' => $empId, 'resolved_at' => now()]);
            }
        }
    }

    protected function emailFor(string $nama): string
    {
        $slug = str(strtolower($nama))->replace(' ', '.')->value();

        return $slug . '@kafe.test';
    }
}
