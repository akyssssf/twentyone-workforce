<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Division;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\SalaryComponent;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Isi 18 karyawan beserta akunnya, sesuai komposisi divisi yang dibutuhkan
 * operasional kafe.
 *
 * Komposisinya sengaja dibuat mendekati kebutuhan nyata (6 chef, 4 barista,
 * 3 kasir, 4 waiter, 2 cleaning = 19... dipangkas jadi 18) supaya saat roster
 * digenerate, peringatan kekurangan tenaga yang muncul memang mencerminkan
 * kondisi sesungguhnya — bukan kekurangan buatan.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::first();
        $pagi = Shift::where('code', 'pagi')->first();
        $malam = Shift::where('code', 'malam')->first();
        $divisions = Division::pluck('id', 'code');
        $gajiPokok = SalaryComponent::where('code', 'gaji_pokok')->first();

        // PIN disesuaikan dengan PIN fisik pada mesin Fingerspot (1, 2, 3, ..., 15).
        $pinMap = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '12', '13', '14', '15', '16', '17', '18', '19'];

        $daftar = [
            // [nama, divisi, shift default, gaji pokok, hari libur]
            ['Andi Pratama', 'chef', $pagi, 4_500_000, [0]],
            ['Budi Santoso', 'chef', $pagi, 4_500_000, [1]],
            ['Cahyo Nugroho', 'chef', $malam, 4_800_000, [2]],
            ['Dedi Kurniawan', 'chef', $malam, 4_800_000, [3]],
            ['Eko Wahyudi', 'chef', $malam, 4_800_000, [4]],
            ['Fajar Ramadhan', 'chef', $malam, 4_600_000, [5]],

            ['Gita Lestari', 'barista', $pagi, 3_800_000, [0]],
            ['Hana Safitri', 'barista', $pagi, 3_800_000, [1]],
            ['Indra Maulana', 'barista', $malam, 4_000_000, [2]],
            ['Joko Susilo', 'barista', $malam, 4_000_000, [3]],

            ['Kartika Dewi', 'kasir', $pagi, 3_600_000, [0]],
            ['Lina Marlina', 'kasir', $malam, 3_800_000, [1]],
            ['Maya Anggraini', 'kasir', $malam, 3_800_000, [2]],

            ['Nanda Saputra', 'waiter', $pagi, 3_400_000, [3]],
            ['Oki Firmansyah', 'waiter', $malam, 3_500_000, [4]],
            ['Putri Handayani', 'waiter', $malam, 3_500_000, [5]],
            ['Rizky Alamsyah', 'waiter', $malam, 3_500_000, [6]],

            ['Sari Wulandari', 'cleaning', $pagi, 3_200_000, [0]],
        ];

        foreach ($daftar as $index => [$nama, $divisi, $shift, $gaji, $libur]) {
            $pin = $pinMap[$index] ?? (string) ($index + 1);

            $employee = Employee::updateOrCreate(
                ['employee_no' => sprintf('EMP-%03d', $index + 10)],
                [
                    'branch_id' => $branch->id,
                    'pin_device' => $pin,
                    'name' => $nama,
                    'email' => $this->emailFor($nama),
                    'default_shift_id' => $shift->id,
                    'preferred_off_days' => $libur,
                    'employment_status' => 'active',
                    'is_active' => true,
                    'joined_at' => now()->subMonths(rand(3, 30))->startOfMonth(),
                ],
            );

            // Pemetaan PIN berperiode — bukan sekadar kolom di employees.
            $employee->devices()->updateOrCreate(
                ['pin' => $pin, 'valid_to' => null],
                [
                    'cloud_id' => config('fingerspot.cloud_id') ?: 'GQ5179086',
                    'valid_from' => $employee->joined_at->toDateString(),
                ],
            );

            $employee->divisions()->syncWithoutDetaching([
                $divisions[$divisi] => ['is_primary' => true, 'competency_level' => 3],
            ]);

            // Kompetensi kedua: waiter yang bisa jadi kasir, barista yang bisa
            // jadi waiter. Inilah yang menyelamatkan operasional saat ada yang
            // sakit mendadak dan headcount sudah mepet.
            $sekunder = match ($divisi) {
                'waiter' => 'kasir',
                'barista' => 'waiter',
                'kasir' => 'waiter',
                default => null,
            };

            if ($sekunder !== null) {
                $employee->divisions()->syncWithoutDetaching([
                    $divisions[$sekunder] => ['is_primary' => false, 'competency_level' => 1],
                ]);
            }

            EmployeeSalary::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'salary_component_id' => $gajiPokok->id,
                    'effective_from' => $employee->joined_at->toDateString(),
                ],
                ['amount' => $gaji],
            );

            // Akun self-service. Password sementara, wajib diganti saat masuk.
            User::updateOrCreate(
                ['employee_id' => $employee->id],
                [
                    'name' => $nama,
                    'email' => $this->emailFor($nama),
                    'password' => Hash::make('karyawan123'),
                    'role' => UserRole::Karyawan,
                    'is_active' => true,
                    'must_change_password' => true,
                ],
            );
        }

        User::updateOrCreate(
            ['email' => 'manager@kafe.test'],
            [
                'name' => 'Manajer Kafe',
                'password' => Hash::make('manager123'),
                'role' => UserRole::Manager,
                'is_active' => true,
            ],
        );

        User::updateOrCreate(
            ['email' => 'owner@kafe.test'],
            [
                'name' => 'Owner Kafe',
                'password' => Hash::make('owner123'),
                'role' => UserRole::Owner,
                'is_active' => true,
            ],
        );
    }

    protected function emailFor(string $nama): string
    {
        $slug = str(strtolower($nama))->replace(' ', '.')->value();

        return $slug . '@kafe.test';
    }
}
