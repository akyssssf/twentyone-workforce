<?php

namespace App\Http\Controllers\Manajer;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\Audit\AuditLogger;
use App\Support\SandiAcak;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $cari = trim((string) $request->query('cari', ''));
        $divisiId = $request->query('divisi');
        $shiftId = $request->query('shift');
        $status = $request->query('status');

        $employees = Employee::query()
            ->with(['divisions', 'defaultShift', 'devices', 'user'])
            ->when($cari !== '', fn ($q) => $q->where(function ($q) use ($cari) {
                $q->where('name', 'like', "%{$cari}%")
                    ->orWhereHas('devices', fn ($q) => $q->where('pin', 'like', "%{$cari}%"));
            }))
            ->when($divisiId, fn ($q) => $q->whereHas('divisions', fn ($q) => $q->where('divisions.id', $divisiId)))
            ->when($shiftId, fn ($q) => $q->where('default_shift_id', $shiftId))
            ->when($status === 'aktif', fn ($q) => $q->where('is_active', true))
            ->when($status === 'nonaktif', fn ($q) => $q->where('is_active', false))
            ->when($status === 'tidak_diabsen', fn ($q) => $q->where('tracks_attendance', false))
            ->when($status === 'tanpa_wa', fn ($q) => $q->where(fn ($q) => $q->whereNull('phone')->orWhere('phone', '')))
            ->orderBy('name')
            ->get();

        return view('manajer.karyawan.index', [
            'employees' => $employees,
            'divisions' => Division::query()->active()->orderBy('sort_order')->get(),
            'shifts' => Shift::query()->active()->orderBy('start_time')->get(),
            'filter' => [
                'cari' => $cari,
                'divisi' => $divisiId,
                'shift' => $shiftId,
                'status' => $status,
            ],
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

    /**
     * Ubah nomor WhatsApp karyawan.
     *
     * Tanpa nomor, kode lembur dan pemberitahuan pengajuan tidak pernah sampai
     * — dan kegagalannya tidak menimbulkan error apa pun, cuma orang yang
     * bertanya-tanya kenapa tidak dapat kabar.
     */
    public function updatePhone(Employee $employee, Request $request)
    {
        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        $lama = $employee->phone;

        // Diseragamkan otomatis oleh mutator di model Employee.
        $employee->update(['phone' => $data['phone'] ?: null]);

        AuditLogger::record('employee.phone_changed', $employee,
            ['phone' => $lama],
            ['phone' => $employee->fresh()->phone],
        );

        return back()->with('status', 'Nomor WhatsApp diperbarui.');
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

    /** Dipindah ke App\Support\SandiAcak: dipakai juga oleh employee:akun. */
    protected function sandiAcak(): string
    {
        return SandiAcak::buat();
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
