<?php

namespace App\Services\Notifications\WhatsApp;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * WhatsApp Cloud API resmi Meta.
 *
 * Lebih tahan lama daripada gateway pihak ketiga — nomornya resmi dan tidak
 * berisiko diblokir — tapi butuh akun bisnis terverifikasi.
 *
 * Catatan penting: di luar jendela 24 jam sejak pesan terakhir dari pengguna,
 * Meta hanya mengizinkan template yang sudah disetujui. Pesan bebas seperti
 * kode lembur akan ditolak. Untuk memakai jalur ini secara serius, kode lembur
 * perlu didaftarkan sebagai template lebih dulu.
 */
class CloudApiDriver implements WhatsAppDriver
{
    public function send(string $nomor, string $pesan): void
    {
        $phoneId = config('whatsapp.cloud.phone_number_id');
        $token = config('whatsapp.cloud.token');

        if (blank($phoneId) || blank($token)) {
            throw new RuntimeException('WHATSAPP_CLOUD_PHONE_ID atau WHATSAPP_CLOUD_TOKEN belum diisi.');
        }

        $versi = config('whatsapp.cloud.version', 'v21.0');

        $response = Http::withToken($token)
            ->timeout(15)
            ->post("https://graph.facebook.com/{$versi}/{$phoneId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $nomor,
                'type' => 'text',
                'text' => ['body' => $pesan],
            ]);

        if ($response->failed()) {
            $alasan = $response->json('error.message') ?? "HTTP {$response->status()}";

            throw new RuntimeException("Cloud API gagal mengirim: {$alasan}");
        }
    }
}
