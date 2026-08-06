<?php

namespace App\Http\Controllers;

use App\Models\DeviceCallback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Penerima webhook realtime Fingerspot.
 *
 * Tugasnya sengaja cuma satu: menelan apa pun yang dikirim mesin, menaruhnya
 * di arsip mentah, lalu balas 200 secepat mungkin. Tidak ada normalisasi,
 * tidak ada hitung telat, tidak ada notifikasi. Semua itu urusan parser yang
 * jalan belakangan dan membaca device_callbacks.
 *
 * Alasannya: kalau logic olahan ikut jalan di sini, satu bug kecil bikin
 * respons non-200 dan scan yang sudah terlanjur dikirim mesin hilang selamanya.
 * Terima dulu, olah belakangan.
 *
 * Bentuk payload attlog menurut dokumentasi:
 *
 *   {
 *     "type": "attlog",
 *     "cloud_id": "XXXXX",
 *     "data": {
 *       "pin": "1",
 *       "scan": "2020-07-21 10:11",
 *       "verify": "1",
 *       "status_scan": "1"
 *     }
 *   }
 *
 * Perhatikan tidak ada trans_id pada push spontan, dan "scan" hanya berpresisi
 * menit.
 */
class FingerspotWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $payload = $this->decodePayload($raw);

        try {
            DeviceCallback::create([
                // Field header diambil best effort. Kalau payload tidak sesuai
                // dugaan, kolomnya null tapi baris tetap tersimpan utuh.
                'cloud_id' => $this->stringOrNull($payload['cloud_id'] ?? null),
                'type' => $this->stringOrNull($payload['type'] ?? null),
                'trans_id' => $this->stringOrNull($payload['trans_id'] ?? null),

                'payload' => $payload,
                'ip' => $request->ip(),

                // Belum ada parser, jadi semua callback masuk sebagai antrian.
                'parsed' => false,
                'parse_error' => null,

                'received_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            // Payload ditulis ke log supaya scan tidak benar-benar hilang
            // walaupun database sedang bermasalah.
            Log::error('Gagal menyimpan callback Fingerspot.', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
                'raw' => $raw,
            ]);

            // Sengaja 500, bukan 200. Kalau nanti Fingerspot punya mekanisme
            // ulang kirim, biar terpicu. Kalau tidak, cron get_attlog yang
            // menambal, dan log di atas jadi bukti kejadiannya.
            return response()->json(['success' => false], 500);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Ubah body jadi array. Body yang bukan JSON tetap harus tersimpan, jadi
     * dibungkus penanda alih-alih dibuang.
     *
     * @return array<string, mixed>
     */
    protected function decodePayload(string $raw): array
    {
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        return [
            '_invalid_json' => true,
            '_json_error' => json_last_error_msg(),
            '_raw' => $raw,
        ];
    }

    protected function stringOrNull(mixed $value): ?string
    {
        // Tolak array/object supaya tidak meledak saat dicor ke kolom string.
        if (is_scalar($value) && $value !== '') {
            return (string) $value;
        }

        return null;
    }
}
