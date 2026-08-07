<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paksa ganti kata sandi awal.
 *
 * Sandi awal dibuat admin dan dibacakan ke orangnya — artinya minimal dua orang
 * pernah tahu, dan tidak jarang tercatat di kertas atau chat. Selama belum
 * diganti, siapa pun yang sempat melihatnya bisa membuka slip gaji orang itu.
 *
 * Sebelumnya kolom must_change_password sudah diisi tapi tidak ada yang
 * menegakkannya — penanda yang tidak berakibat apa-apa.
 */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        // Halaman ganti sandi sendiri dan logout harus tetap bisa dibuka,
        // kalau tidak pengguna terkunci di lingkaran pengalihan.
        if ($request->routeIs('sandi.*', 'logout')) {
            return $next($request);
        }

        return redirect()->route('sandi.edit');
    }
}
