<?php

namespace App\Services\Notifications\WhatsApp;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Gateway WhatsApp lokal (fonnte.com).
 *
 * Dipilih sebagai jalur utama karena paling sederhana untuk kafe: daftar,
 * sambungkan nomor lewat pindai QR, salin token. Tidak perlu akun bisnis
 * terverifikasi maupun template pesan yang harus disetujui dulu.
 *
 * Konsekuensi yang harus disadari: nomor yang dipakai adalah nomor WhatsApp
 * biasa, bukan nomor bisnis resmi. Kalau dipakai mengirim terlalu banyak pesan
 * sekaligus, nomornya bisa diblokir WhatsApp. Untuk 15 karyawan dengan
 * beberapa pesan per hari, itu jauh dari ambang.
 */
class FonnteDriver implements WhatsAppDriver
{
    public function send(string $nomor, string $pesan): void
    {
        $token = config('whatsapp.fonnte.token');

        if (blank($token)) {
            throw new RuntimeException('FONNTE_TOKEN belum diisi.');
        }

        $response = Http::withHeaders(['Authorization' => $token])
            ->asForm()
            // Batas waktu wajib ada: tanpa ini satu gateway yang menggantung
            // akan menahan worker antrean sampai proses dimatikan paksa.
            ->timeout(15)
            ->post(config('whatsapp.fonnte.url'), [
                'target' => $nomor,
                'message' => $pesan,
                'delay' => (string) config('whatsapp.fonnte.delay', 2),
                'countryCode' => '62',
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Fonnte menolak permintaan (HTTP {$response->status()}).");
        }

        // Fonnte membalas 200 walau pengiriman gagal, jadi status di dalam
        // badan respons yang menentukan — bukan kode HTTP-nya.
        $data = $response->json();

        if (($data['status'] ?? false) !== true) {
            $alasan = $data['reason'] ?? $data['detail'] ?? 'tanpa keterangan';

            throw new RuntimeException("Fonnte gagal mengirim: {$alasan}");
        }
    }
}
