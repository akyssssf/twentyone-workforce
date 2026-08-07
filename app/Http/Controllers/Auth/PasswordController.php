<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(Request $request): View
    {
        return view('auth.sandi', [
            'wajib' => (bool) $request->user()->must_change_password,
        ]);
    }

    /**
     * Ganti nama panggilan sendiri.
     *
     * Aturannya sama persis dengan yang dipakai manajer saat mengubah nama
     * panggilan karyawan lain (lihat EmployeeController::updateUsername) —
     * huruf kecil dan angka saja, supaya tetap gampang diketik di ponsel.
     */
    public function updateUsername(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'username' => [
                'required', 'string', 'min:3', 'max:32',
                'regex:/^[a-z0-9]+$/',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
        ], [
            'username.regex' => 'Nama panggilan hanya boleh huruf kecil dan angka, tanpa spasi.',
            'username.unique' => 'Nama panggilan itu sudah dipakai orang lain.',
        ]);

        $lama = $user->username;
        $user->update(['username' => $data['username']]);

        AuditLogger::record('user.username_changed', $user, ['username' => $lama], $data);

        return back()->with('status', "Nama panggilan diubah jadi \"{$data['username']}\".");
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            // Sandi lama tetap diminta walau sedang dipaksa ganti: tanpa itu,
            // sesi yang tertinggal terbuka di ponsel orang lain bisa dipakai
            // mengunci pemilik akun keluar dari akunnya sendiri.
            'sandi_lama' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'sandi_lama.current_password' => 'Kata sandi lama salah.',
            'password.confirmed' => 'Ulangi kata sandi tidak cocok.',
        ]);

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'must_change_password' => false,
        ])->save();

        AuditLogger::record('user.password_changed', $user);

        return redirect()->route('beranda')->with('status', 'Kata sandi berhasil diganti.');
    }
}
