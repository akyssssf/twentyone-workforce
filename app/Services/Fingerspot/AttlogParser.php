<?php

namespace App\Services\Fingerspot;

use App\Models\AttendanceLog;
use App\Models\DeviceCallback;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Pemindah data mentah ke data olahan tahap pertama:
 * device_callbacks -> attendance_logs.
 *
 * Seluruh proses ini idempoten. Menjalankan ulang callback yang sama tidak
 * akan menghasilkan baris ganda, karena penjaga terakhirnya adalah unique
 * (cloud_id, pin, scan_minute) di level database, bukan pengecekan di PHP.
 * Pengecekan di PHP selalu bisa kalah balapan kalau dua proses jalan bareng;
 * unique constraint tidak.
 */
class AttlogParser
{
    /**
     * Format waktu yang mungkin dikirim Fingerspot. Webhook memakai bentuk
     * tanpa detik, get_attlog memakai yang berdetik.
     */
    protected const TIME_FORMATS = ['Y-m-d H:i:s', 'Y-m-d H:i'];

    /**
     * Proses satu callback dan tandai hasilnya.
     *
     * @return array{created: int, duplicate: int, skipped: bool}
     */
    public function parseCallback(DeviceCallback $callback): array
    {
        // Callback selain attlog (get_userinfo, set_time, dan lainnya) tidak
        // punya data scan. Sudah tersimpan di arsip mentah, jadi tugasnya
        // selesai: tandai parsed supaya tidak terus muncul di antrian.
        if ($callback->type !== 'attlog') {
            $callback->forceFill(['parsed' => true, 'parse_error' => null])->save();

            return ['created' => 0, 'duplicate' => 0, 'skipped' => true];
        }

        try {
            $scans = $this->extractScans($callback->payload ?? []);
            $cloudId = $this->extractCloudId($callback);
        } catch (InvalidScanPayload $e) {
            // Rusak permanen. Ditandai parsed supaya berhenti diulang, tapi
            // alasannya disimpan supaya tetap kelihatan saat audit.
            $callback->forceFill([
                'parsed' => true,
                'parse_error' => $e->getMessage(),
            ])->save();

            return ['created' => 0, 'duplicate' => 0, 'skipped' => true];
        }

        // Kegagalan database di luar duplikat sengaja dibiarkan naik ke atas
        // tanpa ditangkap, supaya parsed tetap false dan callback ini dicoba
        // lagi di putaran berikutnya.
        $hasil = $this->storeScans($scans, $cloudId, 'webhook', $callback->id);

        $callback->forceFill(['parsed' => true, 'parse_error' => null])->save();

        return $hasil + ['skipped' => false];
    }

    /**
     * Tulis sekumpulan scan ke attendance_logs.
     *
     * Dipakai dua jalur sekaligus: webhook realtime dan cron get_attlog. Yang
     * membedakan hanya nilai source. Penjaga duplikatnya sengaja unique
     * constraint di database, bukan pengecekan di PHP, karena dua jalur ini
     * bisa jalan bersamaan dan pengecekan di PHP selalu bisa kalah balapan.
     *
     * @param  array<int, ScanData>  $scans
     * @return array{created: int, duplicate: int}
     */
    public function storeScans(array $scans, string $cloudId, string $source, ?int $callbackId = null): array
    {
        $created = 0;
        $duplicate = 0;

        foreach ($scans as $scan) {
            try {
                AttendanceLog::create(
                    $scan->toLogAttributes($cloudId, $source, $callbackId)
                );
                $created++;
            } catch (UniqueConstraintViolationException) {
                // Bukan error. Scan ini sudah pernah masuk, entah dari callback
                // kembar yang dikirim ulang mesin, atau dari jalur satunya yang
                // lebih dulu menambal. Persis fungsi yang diharapkan.
                $duplicate++;
            }
        }

        return ['created' => $created, 'duplicate' => $duplicate];
    }

    /**
     * Ambil daftar scan dari payload.
     *
     * Webhook attlog mengirim "data" sebagai satu objek, sedangkan respons
     * get_attlog mengirimnya sebagai array objek. Keduanya diterima supaya
     * parser ini bisa dipakai ulang jalur cron nanti.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, ScanData>
     */
    public function extractScans(array $payload): array
    {
        $data = $payload['data'] ?? null;

        if (! is_array($data) || $data === []) {
            throw new InvalidScanPayload('Payload attlog tanpa isi "data".');
        }

        // Objek tunggal dibungkus jadi array satu elemen supaya alurnya seragam.
        $rows = array_is_list($data) ? $data : [$data];

        $scans = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidScanPayload('Elemen "data" bukan objek.');
            }

            $scans[] = $this->makeScan($row);
        }

        return $scans;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function makeScan(array $row): ScanData
    {
        $pin = $row['pin'] ?? null;

        // PIN "0" itu sah, jadi jangan pakai empty().
        if (! is_scalar($pin) || (string) $pin === '') {
            throw new InvalidScanPayload('Scan tanpa "pin".');
        }

        // Webhook memakai "scan", get_attlog memakai "scan_date".
        $rawTime = $row['scan'] ?? $row['scan_date'] ?? null;

        if (! is_string($rawTime) || trim($rawTime) === '') {
            throw new InvalidScanPayload('Scan tanpa waktu ("scan"/"scan_date").');
        }

        $photo = $row['photo_url'] ?? null;

        return new ScanData(
            pin: (string) $pin,
            scannedAt: $this->parseTime(trim($rawTime)),
            verifyMode: $this->intOrNull($row['verify'] ?? null),
            statusScan: $this->intOrNull($row['status_scan'] ?? null),
            raw: $row,
            // Cuma dikirim mesin yang berkamera. Selain itu tetap null, dan
            // itu bukan kesalahan.
            photoUrl: is_string($photo) && $photo !== '' ? $photo : null,
        );
    }

    /**
     * Mesin mengirim waktu lokal polos tanpa offset, jadi selalu ditafsirkan
     * di zona operasional, bukan zona server.
     */
    protected function parseTime(string $value): Carbon
    {
        $timezone = config('attendance.timezone', 'Asia/Jakarta');

        foreach (self::TIME_FORMATS as $format) {
            try {
                // Beda dengan DateTime bawaan PHP, Carbon melempar exception
                // saat format tidak cocok, bukan mengembalikan false. Jadi tiap
                // percobaan harus dibungkus supaya format berikutnya kebagian
                // giliran.
                $parsed = Carbon::createFromFormat($format, $value, $timezone);
            } catch (\Exception) {
                continue;
            }

            // createFromFormat menerima "2026-02-31" lalu menggesernya diam-diam
            // ke 3 Maret. Hasilnya dibandingkan balik ke string asal supaya
            // tanggal ngawur ditolak, bukan bergeser tanpa ketahuan.
            if ($parsed->format($format) === $value) {
                return $parsed;
            }
        }

        throw new InvalidScanPayload("Format waktu scan tidak dikenali: \"{$value}\".");
    }

    protected function extractCloudId(DeviceCallback $callback): string
    {
        // Kolom hasil ekstraksi saat penerimaan lebih dipercaya, tapi payload
        // tetap dijadikan cadangan.
        $cloudId = $callback->cloud_id ?? ($callback->payload['cloud_id'] ?? null);

        if (! is_scalar($cloudId) || (string) $cloudId === '') {
            throw new InvalidScanPayload('Callback attlog tanpa cloud_id.');
        }

        return (string) $cloudId;
    }

    protected function intOrNull(mixed $value): ?int
    {
        // Mesin mengirim kode ini sebagai string ("1"), jadi dicek numerik
        // dulu, bukan is_int().
        return is_numeric($value) ? (int) $value : null;
    }
}
