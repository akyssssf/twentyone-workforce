<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal karyawan butuh akun yang benar-benar terhubung ke satu baris
 * employees. Manager yang nyasar ke sini dikembalikan ke dashboard-nya.
 */
class EnsureUserIsEmployee
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if (! $user->isEmployee() || $user->employee_id === null) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
