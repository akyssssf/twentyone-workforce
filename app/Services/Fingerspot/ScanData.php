<?php

namespace App\Services\Fingerspot;

use Illuminate\Support\Carbon;

/**
 * Satu scan yang sudah dinormalisasi, apa pun jalur asalnya.
 *
 * Gunanya menyeragamkan dua bentuk data yang berbeda dari Fingerspot:
 * webhook memakai kunci "scan" berpresisi menit, sedangkan get_attlog memakai
 * kunci "scan_date" berpresisi detik. Setelah lewat kelas ini, sisa aplikasi
 * tidak perlu tahu bedanya.
 */
class ScanData
{
    public function __construct(
        public readonly string $pin,
        public readonly Carbon $scannedAt,
        public readonly ?int $verifyMode,
        public readonly ?int $statusScan,
        public readonly array $raw,
        public readonly ?string $photoUrl = null,
    ) {}

    /**
     * Salinan waktu scan dengan detik dipangkas nol. Ini yang dipakai sebagai
     * kunci anti-duplikat, supaya scan yang sama dari webhook (10:11:00) dan
     * dari get_attlog (10:11:29) dikenali sebagai satu kejadian.
     */
    public function scanMinute(): Carbon
    {
        return $this->scannedAt->copy()->startOfMinute();
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogAttributes(string $cloudId, string $source, ?int $deviceCallbackId = null): array
    {
        return [
            'cloud_id' => $cloudId,
            'pin' => $this->pin,
            'scanned_at' => $this->scannedAt,
            'scan_minute' => $this->scanMinute(),
            'verify_mode' => $this->verifyMode,
            'status_scan' => $this->statusScan,

            // Fingerspot tidak mengirim io_mode. Lihat catatan di migration
            // attendance_logs.
            'io_mode' => null,

            'photo_url' => $this->photoUrl,
            'source' => $source,
            'payload' => $this->raw,
            'device_callback_id' => $deviceCallbackId,
        ];
    }
}
