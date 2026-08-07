<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string'],
        ]);

        // Nama panggilan diperlakukan tanpa peduli huruf besar-kecil. Orang
        // mengetik "Dian" di ponsel yang otomatis mengapitalkan huruf pertama,
        // dan gagal login karena itu adalah cara tercepat membuat mereka
        // berhenti memakai aplikasi ini.
        $kredensial = [
            'username' => mb_strtolower(trim($data['username'])),
            'password' => $data['password'],
        ];

        // Pembatas percobaan: 5 kali per kombinasi nama pengguna dan IP.
        // Tanpa ini kata sandi bisa ditebak terus-menerus tanpa hambatan.
        $kunci = 'login:'.$kredensial['username'].'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($kunci, 5)) {
            throw ValidationException::withMessages([
                'username' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($kunci).' detik.',
            ]);
        }

        if (! Auth::attempt($kredensial, $request->boolean('remember'))) {
            RateLimiter::hit($kunci, 60);

            throw ValidationException::withMessages([
                // Pesan sengaja tidak memisahkan "nama tidak ada" dari "sandi
                // salah", supaya tidak bisa dipakai menebak siapa yang punya
                // akun di sini.
                'username' => 'Nama atau kata sandi salah.',
            ]);
        }

        if (! $request->user()->is_active) {
            Auth::logout();

            throw ValidationException::withMessages([
                'username' => 'Akun ini sudah dinonaktifkan.',
            ]);
        }

        RateLimiter::clear($kunci);

        // Ganti id sesi setelah login supaya sesi lama tidak bisa dipakai
        // lagi kalau sempat bocor.
        $request->session()->regenerate();

        $request->user()->forceFill(['last_login_at' => now()])->save();

        // Karyawan dan manajer punya beranda yang berbeda; /beranda yang
        // memutuskan, bukan controller ini.
        return redirect()->intended(route('beranda'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
