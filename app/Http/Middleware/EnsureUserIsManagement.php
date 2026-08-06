<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang RBAC untuk seluruh area manajer.
 *
 * Karyawan yang mencoba membuka halaman manajer dilempar ke portalnya sendiri,
 * bukan diberi 403 telanjang — yang salah bukan dia, cuma nyasar.
 */
class EnsureUserIsManagement
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if (! $user->isManagement()) {
            return redirect()->route('karyawan.beranda');
        }

        return $next($request);
    }
}
