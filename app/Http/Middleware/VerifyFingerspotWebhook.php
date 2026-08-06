<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Satu-satunya lapisan otentikasi webhook Fingerspot.
 *
 * Fingerspot tidak mengirim signature, HMAC, atau header rahasia apa pun, jadi
 * yang bisa dipakai cuma secret yang ditanam di segmen URL. Konsekuensinya
 * secret ini ikut terekam di access log web server dan di riwayat browser
 * siapa pun yang pernah membukanya, jadi perlakukan seperti password.
 */
class VerifyFingerspotWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('fingerspot.webhook_secret');

        // Secret kosong berarti salah konfigurasi. Jangan pernah anggap ini
        // sebagai "boleh lewat", karena hash_equals('', '') bernilai true dan
        // endpoint jadi terbuka untuk siapa saja.
        if (! is_string($expected) || $expected === '') {
            Log::critical('FINGERSPOT_WEBHOOK_SECRET belum diisi, webhook ditolak.');

            abort(404);
        }

        $given = (string) $request->route('secret');

        // hash_equals membandingkan dalam waktu konstan supaya secret tidak
        // bisa ditebak karakter per karakter lewat pengukuran waktu respons.
        if (! hash_equals($expected, $given)) {
            Log::warning('Webhook Fingerspot ditolak, secret tidak cocok.', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            // 404, bukan 401/403, supaya penyerang tidak dapat konfirmasi
            // bahwa endpoint ini ada dan tinggal menebak secretnya.
            abort(404);
        }

        return $next($request);
    }
}
