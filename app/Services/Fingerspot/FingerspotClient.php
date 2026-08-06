<?php

namespace App\Services\Fingerspot;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Pembungkus panggilan HTTP ke developer.fingerspot.io.
 *
 * Semua endpoint di API ini memakai POST dan Bearer token, termasuk yang
 * sifatnya cuma membaca.
 */
class FingerspotClient
{
    public function __construct(
        protected ?string $token = null,
        protected ?string $cloudId = null,
        protected ?string $baseUrl = null,
    ) {
        $this->token ??= config('fingerspot.api_token');
        $this->cloudId ??= config('fingerspot.cloud_id');
        $this->baseUrl ??= config('fingerspot.api_url');
    }

    /**
     * Info perangkat. Sinkron, read-only, dan aman diulang berkali-kali, jadi
     * ini cara paling murah memastikan token dan cloud_id benar.
     *
     * Responsnya sekalian memuat webhook_url yang sedang terdaftar.
     *
     * @return array<string, mixed>
     */
    public function getDevice(?string $cloudId = null): array
    {
        $response = $this->post('get_device', [
            'trans_id' => $this->transId(),
            'cloud_id' => $this->resolveCloudId($cloudId),
        ]);

        $data = $response['data'] ?? null;

        if (! is_array($data)) {
            throw new FingerspotException('Respons get_device tanpa isi "data".');
        }

        return $data;
    }

    /**
     * Tarik log absensi tersimpan untuk satu rentang tanggal.
     *
     * Dua batasan dari dokumentasi ditegakkan di sini, bukan dipercayakan ke
     * pemanggil, supaya tidak ada jalan menabraknya tanpa sengaja:
     * data hanya tersedia 60 hari ke belakang, dan satu permintaan maksimal
     * mencakup 2 hari (inklusif).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAttlog(Carbon $startDate, Carbon $endDate, ?string $cloudId = null): array
    {
        $start = $startDate->copy()->startOfDay();
        $end = $endDate->copy()->startOfDay();

        if ($start->greaterThan($end)) {
            throw new FingerspotException('start_date tidak boleh melewati end_date.');
        }

        // diffInDays 0 berarti satu hari, 1 berarti dua hari. Keduanya sah.
        $spanDays = $start->diffInDays($end) + 1;
        $maxDays = (int) config('fingerspot.max_days_per_request', 2);

        if ($spanDays > $maxDays) {
            throw new FingerspotException(
                "Rentang {$spanDays} hari melebihi batas {$maxDays} hari per permintaan."
            );
        }

        $retention = (int) config('fingerspot.retention_days', 60);

        if ($start->lessThan(Carbon::today($this->timezone())->subDays($retention))) {
            throw new FingerspotException(
                "start_date di luar retensi {$retention} hari, data sudah tidak tersedia."
            );
        }

        $response = $this->post('get_attlog', [
            'trans_id' => $this->transId(),
            'cloud_id' => $this->resolveCloudId($cloudId),
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ]);

        $data = $response['data'] ?? [];

        // Perangkat tanpa scan di rentang itu wajar mengembalikan data kosong.
        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function post(string $endpoint, array $body): array
    {
        if (! is_string($this->token) || $this->token === '') {
            throw new FingerspotException('FINGERSPOT_API_TOKEN belum diisi.');
        }

        $url = $this->baseUrl.'/'.ltrim($endpoint, '/');

        try {
            $response = $this->request()->post($url, $body);
        } catch (\Throwable $e) {
            // Jaringan putus atau timeout. Dibungkus supaya pemanggil cukup
            // menangkap satu jenis exception.
            throw new FingerspotException(
                "Gagal menghubungi {$endpoint}: {$e->getMessage()}", previous: $e
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new FingerspotException(
                "Token ditolak Fingerspot (HTTP {$response->status()}). Periksa FINGERSPOT_API_TOKEN."
            );
        }

        if ($response->failed()) {
            throw new FingerspotException(
                "Endpoint {$endpoint} membalas HTTP {$response->status()}: ".mb_substr($response->body(), 0, 300)
            );
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new FingerspotException("Respons {$endpoint} bukan JSON yang sah.");
        }

        // HTTP 200 belum tentu berhasil. API ini menandai kegagalan lewat
        // field success di body, jadi status code saja tidak cukup.
        if (($json['success'] ?? false) !== true) {
            $pesan = $json['message'] ?? $json['error'] ?? 'tanpa keterangan';

            throw new FingerspotException("Endpoint {$endpoint} menolak permintaan: {$pesan}");
        }

        return $json;
    }

    protected function request(): PendingRequest
    {
        return Http::withToken($this->token)
            ->acceptJson()
            ->asJson()
            // Webhook adalah jalur utama, jadi panggilan ini tidak perlu
            // ngotot. Lebih baik gagal cepat lalu dicoba lagi besok.
            ->timeout(30)
            ->connectTimeout(10)
            ->retry(2, 1000, throw: false);
    }

    protected function resolveCloudId(?string $cloudId): string
    {
        $resolved = $cloudId ?? $this->cloudId;

        if (! is_string($resolved) || $resolved === '') {
            throw new FingerspotException('FINGERSPOT_CLOUD_ID belum diisi.');
        }

        return $resolved;
    }

    /**
     * trans_id wajib ada dan dipilih pemanggil. Untuk endpoint sinkron nilainya
     * tidak dipakai mengaitkan apa pun, tapi tetap harus unik supaya jejaknya
     * bisa ditelusuri di sisi Fingerspot.
     */
    protected function transId(): string
    {
        return (string) Carbon::now()->getTimestampMs();
    }

    protected function timezone(): string
    {
        return config('attendance.timezone', 'Asia/Jakarta');
    }
}
