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
        // Bersihkan data karyawan & user lama.
        //
        // JANGAN mematikan PRAGMA foreign_keys untuk ini. Mematikannya membuat
        // penghapusan "berhasil" tanpa membersihkan baris anak, dan yang
        // tertinggal adalah jadwal serta absensi yatim yang menunjuk karyawan
        // yang sudah tidak ada. Baris yatim itu tidak menimbulkan error apa
        // pun — ia cuma diam-diam menggelembungkan angka di dashboard dan
        // laporan sampai ada yang curiga.
        //
        // Yang benar: hapus anak-anaknya lebih dulu, biarkan foreign key tetap
        // menyala sebagai jaring pengaman.
        $employeeIds = Employee::withTrashed()->pluck('id');

        if ($employeeIds->isNotEmpty()) {
            foreach ([
                'attendance_adjustments', 'attendances', 'roster_assignments',
                'leave_balances', 'overtime_records', 'employee_salaries',
                'employee_devices', 'payslips', 'manual_payroll_entries',
            ] as $tabel) {
                DB::table($tabel)->whereIn('employee_id', $employeeIds)->delete();
            }

            // Pengajuan punya tabel detail yang menempel lewat request_id.
            $requestIds = DB::table('requests')->whereIn('employee_id', $employeeIds)->pluck('id');

            if ($requestIds->isNotEmpty()) {
                foreach ([
                    'leave_requests', 'overtime_requests',
                    'shift_swap_requests', 'attendance_corrections', 'request_attachments',
                ] as $tabel) {
                    DB::table($tabel)->whereIn('request_id', $requestIds)->delete();
                }

                DB::table('requests')->whereIn('id', $requestIds)->delete();
            }

            // Scan mentah TIDAK dihapus — itu arsip. Cukup lepaskan tautannya
            // supaya bisa dicocokkan ulang ke karyawan yang baru dibuat.
            DB::table('attendance_logs')
                ->whereIn('employee_id', $employeeIds)
                ->update(['employee_id' => null, 'resolved_at' => null]);
        }

        User::withTrashed()->forceDelete();
        Employee::withTrashed()->forceDelete();

        $branch = Branch::first();
        $pagi = Shift::where('code', 'pagi')->first();
        $malam = Shift::where('code', 'malam')->first();
        $divisions = Division::pluck('id', 'code');
        $gajiPokok = SalaryComponent::where('code', 'gaji_pokok')->first();

        // Data real PIN & nama dari mesin Fingerspot (GQ5179086)
        // Nama diambil langsung dari Person Management di developer.fingerspot.io
        $realPinMap = [
            '1'  => ['name' => '21 Zafan',                       'divisi' => 'admin',    'shift' => $pagi,  'gaji' => 4_500_000, 'libur' => [0]],
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
            '13' => ['name' => '21 Bryan',                       'divisi' => 'admin',    'shift' => $pagi,  'gaji' => 4_500_000, 'libur' => [6]],
            '14' => ['name' => 'Jihan Yuni Ariszqi',             'divisi' => 'barista',  'shift' => $malam, 'gaji' => 4_000_000, 'libur' => [0]],
            '15' => ['name' => 'Setia Pribadi Bogel',            'divisi' => 'waiter',   'shift' => $pagi,  'gaji' => 3_500_000, 'libur' => [1]],
        ];

        $cloudId = config('fingerspot.cloud_id') ?: 'GQ5179086';
        $index = 0;

        $this->usernameTerpakai = [];
        $kredensial = [];

        foreach ($realPinMap as $pin => $spec) {
            $index++;
            $nama = $spec['name'];
            $divisi = $spec['divisi'];
            $shift = $spec['shift'];
            $gaji = $spec['gaji'];
            $libur = $spec['libur'];
            $isAdmin = in_array((string) $pin, ['1', '13'], true);

            $employee = Employee::create([
                'branch_id' => $branch->id,
                'employee_no' => sprintf('EMP-%03d', $index),
                'pin_device' => (string) $pin,
                'name' => $nama,
                'email' => $this->emailFor($nama),
                'default_shift_id' => $shift->id,
                'preferred_off_days' => $libur,
                // employment_status menjawab "masih bekerja atau sudah resign".
                // Admin tetap pegawai aktif — yang membedakan mereka adalah
                // tidak ikut diabsen, dan itu kolomnya sendiri.
                'employment_status' => 'active',
                'is_active' => true,

                // Admin punya akun dan digaji, tapi tidak menempel jari di
                // mesin dan tidak masuk roster. Tanpa penanda ini mereka
                // muncul sebagai Alpha setiap hari dan kena potongan alpha.
                'tracks_attendance' => ! $isAdmin,
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

            // Kata sandi BERBEDA untuk tiap orang. Kalau seragam, siapa pun
            // yang tahu polanya bisa masuk sebagai rekannya — dan yang paling
            // sering dibuka orang lain justru slip gaji.
            $sandi = $this->sandiAcak();
            $username = $this->usernameUntuk($nama, $isAdmin);

            User::create([
                'employee_id' => $employee->id,
                'username' => $username,
                'name' => $nama,
                'email' => $this->emailFor($nama),
                'password' => Hash::make($sandi),
                'role' => $isAdmin ? UserRole::Admin : UserRole::Karyawan,
                'is_active' => true,
                'must_change_password' => ! $isAdmin,
            ]);

            $kredensial[] = [$username, $nama, $isAdmin ? 'Admin' : 'Karyawan', $sandi];
        }

        // Akun admin utama. Namanya sengaja cuma "admin" — ini yang dipakai
        // sehari-hari dan harus paling mudah diketik.
        User::create([
            'username' => 'admin',
            'name' => 'Admin Kafe',
            'email' => 'admin@kafe.test',
            'password' => Hash::make('admin123'),
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $kredensial[] = ['admin', 'Admin Kafe', 'Admin', 'admin123'];

        // Saldo cuti tahun berjalan.
        //
        // Dibuatkan di depan, bukan menunggu pengajuan pertama, supaya karyawan
        // bisa melihat sisa jatahnya sebelum memutuskan mengajukan — bukan
        // setelah.
        $leaveTypes = \App\Models\LeaveType::where('deducts_balance', true)->get();

        foreach (Employee::all() as $employee) {
            foreach ($leaveTypes as $type) {
                \App\Models\LeaveBalance::updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'leave_type_id' => $type->id,
                        'year' => (int) now()->year,
                    ],
                    ['entitlement_days' => $type->default_entitlement_days],
                );
            }
        }

        // Daftar kredensial dicetak sekali di sini karena kata sandi tidak
        // pernah disimpan dalam bentuk terbaca. Setelah layar ini lewat,
        // satu-satunya cara mendapatkannya lagi adalah mengatur ulang.
        if ($this->command !== null) {
            $this->command->newLine();
            $this->command->warn('Kredensial awal — catat sekarang, tidak bisa dilihat lagi:');
            $this->command->table(['Login', 'Nama', 'Peran', 'Kata sandi'], $kredensial);
        }

        // Hubungkan ulang semua log absensi ke karyawan baru
        foreach (AttendanceLog::whereNull('employee_id')->get() as $log) {
            $empId = EmployeeDevice::resolveEmployeeId($log->pin, $log->scanned_at, $log->cloud_id)
                ?? EmployeeDevice::resolveEmployeeId($log->pin, $log->scanned_at);

            if ($empId !== null) {
                $log->update(['employee_id' => $empId, 'resolved_at' => now()]);
            }
        }
    }

    /** @var array<int, string> */
    protected array $usernameTerpakai = [];

    /**
     * Nama panggilan untuk login.
     *
     * Nama depan, huruf kecil, tanpa spasi. Kalau bentrok, disambung huruf
     * berikutnya dari nama belakang — "dian" lalu "dians" — bukan angka,
     * karena angka di belakang nama paling sering salah diingat.
     */
    protected function usernameUntuk(string $nama, bool $isAdmin): string
    {
        $bagian = preg_split('/\s+/', mb_strtolower(trim($nama))) ?: [];
        $bagian = array_values(array_filter(array_map(
            fn ($b) => preg_replace('/[^a-z0-9]/', '', $b),
            $bagian,
        )));

        // Nama seperti "21 Zafan" berawal angka; ambil potongan pertama yang
        // benar-benar huruf supaya usernamenya tidak jadi "21".
        $dasar = '';

        foreach ($bagian as $b) {
            if (preg_match('/^[a-z]/', $b)) {
                $dasar = $b;

                break;
            }
        }

        $dasar = $dasar ?: 'user';
        $sisa = implode('', array_slice($bagian, array_search($dasar, $bagian, true) + 1));

        $username = $dasar;
        $i = 0;

        while (in_array($username, $this->usernameTerpakai, true)) {
            $username = $i < strlen($sisa) ? $dasar . substr($sisa, 0, $i + 1) : $dasar . ($i + 1);
            $i++;
        }

        $this->usernameTerpakai[] = $username;

        return $username;
    }

    /**
     * Kata sandi awal yang mudah dibacakan tapi tidak mudah ditebak.
     *
     * Huruf yang rancu saat dibacakan (0/O, 1/l/I) dibuang — sandi yang salah
     * dengar berarti karyawan gagal masuk di hari pertama dan tidak mencoba
     * lagi.
     */
    protected function sandiAcak(): string
    {
        $abjad = 'abcdefghjkmnpqrstuvwxyz23456789';
        $sandi = '';

        for ($i = 0; $i < 8; $i++) {
            $sandi .= $abjad[random_int(0, strlen($abjad) - 1)];
        }

        return $sandi;
    }

    protected function emailFor(string $nama): string
    {
        $slug = str(strtolower($nama))->replace(' ', '.')->value();

        return $slug . '@kafe.test';
    }
}
