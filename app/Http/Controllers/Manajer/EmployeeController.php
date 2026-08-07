<?php

namespace App\Http\Controllers\Manajer;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\Employee;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index()
    {
        return view('manajer.karyawan.index', [
            'employees' => Employee::query()
                ->with(['divisions', 'defaultShift', 'devices', 'user'])
                ->orderBy('name')
                ->get(),
            'divisions' => Division::query()->active()->orderBy('sort_order')->get(),
        ]);
    }

    public function show(Employee $employee)
    {
        return view('manajer.karyawan.show', [
            'employee' => $employee->load(['divisions', 'devices', 'salaries.component', 'leaveBalances.leaveType', 'user']),
            'divisions' => Division::query()->active()->orderBy('sort_order')->get(),
        ]);
    }

    /**
     * Atur apakah karyawan ini ikut diabsen.
     *
     * Mematikannya juga membersihkan absensi & jadwal yang sudah terlanjur
     * dibuat untuk dia. Kedua tabel itu murni turunan, jadi menghapusnya tidak
     * menghilangkan apa pun yang tidak bisa dibangun ulang — sementara
     * membiarkannya berarti admin tetap muncul sebagai Alpha di laporan
     * bulan berjalan.
     */
    public function updateTracking(Employee $employee, Request $request)
    {
        $tracks = $request->boolean('tracks_attendance');
        $sebelum = $employee->tracks_attendance;

        $employee->update(['tracks_attendance' => $tracks]);

        $dibersihkan = 0;

        if (! $tracks && $sebelum) {
            $dibersihkan = $employee->attendances()->delete();
            $employee->rosterAssignments()->delete();
        }

        AuditLogger::record('employee.attendance_tracking_changed', $employee,
            ['tracks_attendance' => $sebelum],
            ['tracks_attendance' => $tracks],
        );

        return back()->with('status', $tracks
            ? "{$employee->name} sekarang ikut diabsen dan dijadwalkan."
            : "{$employee->name} tidak lagi diabsen." . ($dibersihkan > 0
                ? " {$dibersihkan} catatan absensi yang terlanjur dibuat sudah dibersihkan."
                : ''));
    }

    /**
     * Atur ulang kata sandi karyawan.
     *
     * Sandi baru ditampilkan SEKALI lewat flash session, lalu tidak pernah
     * bisa dilihat lagi — yang tersimpan cuma hash-nya. Admin membacakannya
     * ke orangnya saat itu juga.
     */
    public function resetPassword(Employee $employee, Request $request)
    {
        $akun = $employee->user;

        if ($akun === null) {
            return back()->withErrors(['akun' => "{$employee->name} belum punya akun login."]);
        }

        $sandi = $this->sandiAcak();

        $akun->forceFill([
            'password' => Hash::make($sandi),
            'must_change_password' => true,
        ])->save();

        AuditLogger::record('user.password_reset', $akun, [], ['employee' => $employee->name]);

        return back()
            ->with('status', "Kata sandi {$employee->name} sudah diatur ulang.")
            ->with('sandi_baru', $sandi)
            ->with('sandi_untuk', $akun->username);
    }

    /** Ubah nama panggilan untuk login. */
    public function updateUsername(Employee $employee, Request $request)
    {
        $akun = $employee->user;

        if ($akun === null) {
            return back()->withErrors(['akun' => "{$employee->name} belum punya akun login."]);
        }

        $data = $request->validate([
            'username' => [
                'required', 'string', 'min:3', 'max:32',
                // Huruf kecil dan angka saja: nama panggilan yang mengandung
                // spasi atau huruf besar akan gagal diketik di ponsel.
                'regex:/^[a-z0-9]+$/',
                Rule::unique('users', 'username')->ignore($akun->id),
            ],
        ], [
            'username.regex' => 'Nama panggilan hanya boleh huruf kecil dan angka, tanpa spasi.',
            'username.unique' => 'Nama panggilan itu sudah dipakai orang lain.',
        ]);

        $lama = $akun->username;
        $akun->update(['username' => $data['username']]);

        AuditLogger::record('user.username_changed', $akun, ['username' => $lama], $data);

        return back()->with('status', "Nama panggilan diubah jadi \"{$data['username']}\".");
    }

    protected function sandiAcak(): string
    {
        // Tanpa huruf/angka yang rancu saat dibacakan: 0/O, 1/l/I.
        $abjad = 'abcdefghjkmnpqrstuvwxyz23456789';
        $sandi = '';

        for ($i = 0; $i < 8; $i++) {
            $sandi .= $abjad[random_int(0, strlen($abjad) - 1)];
        }

        return $sandi;
    }

    public function syncDivisions(Employee $employee, Request $request)
    {
        $data = $request->validate([
            'divisions' => ['required', 'array', 'min:1'],
            'divisions.*' => ['exists:divisions,id'],
            'primary' => ['required', 'exists:divisions,id'],
        ]);

        $payload = [];

        foreach ($data['divisions'] as $id) {
            $payload[$id] = [
                'is_primary' => (int) $id === (int) $data['primary'],
                'competency_level' => (int) $id === (int) $data['primary'] ? 3 : 1,
            ];
        }

        $employee->divisions()->sync($payload);

        AuditLogger::record('employee.divisions_changed', $employee, [], $payload);

        return back()->with('status', 'Divisi diperbarui.');
    }
}
