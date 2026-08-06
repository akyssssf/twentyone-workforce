<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pembatas aksi yang hanya boleh dilakukan owner: mengelola akun dashboard,
 * mengubah gaji pokok, menonaktifkan karyawan.
 */
class EnsureUserIsOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isOwner()) {
            abort(403, 'Halaman ini hanya untuk owner.');
        }

        return $next($request);
    }
}
