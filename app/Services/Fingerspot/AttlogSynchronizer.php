<?php

namespace App\Services\Fingerspot;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Jalur cadangan: tarik log absensi dari Fingerspot lewat get_attlog.
 *
 * Gunanya menambal scan yang webhook-nya kelewat, entah karena server sempat
 * mati, jaringan putus, atau webhook belum sempat dikonfigurasi. Karena ini
 * tarikan, bukan dorongan, jalur ini tetap bekerja walau aplikasi belum punya
 * alamat publik sama sekali.
 *
 * Aman dijalankan berulang: scan yang sudah ada ditolak unique constraint
 * (cloud_id, pin, scan_minute), termasuk scan yang sudah lebih dulu masuk
 * lewat webhook dengan presisi menit.
 */
class AttlogSynchronizer
{
    public function __construct(
        protected FingerspotClient $client,
        protected AttlogParser $parser,
    ) {}

    /**
     * Tarik satu rentang tanggal, dipotong otomatis sesuai batas API.
     *
     * @return array{created: int, duplicate: int, invalid: int, chunks: int, rows: int}
     */
    public function syncRange(Carbon $from, Carbon $to, ?string $cloudId = null): array
    {
        $cloudId ??= config('fingerspot.cloud_id');

        $hasil = ['created' => 0, 'duplicate' => 0, 'invalid' => 0, 'chunks' => 0, 'rows' => 0];

        foreach ($this->chunks($from, $to) as [$mulai, $selesai]) {
            $rows = $this->client->getAttlog($mulai, $selesai, $cloudId);

            $hasil['chunks']++;
            $hasil['rows'] += count($rows);

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    $hasil['invalid']++;

                    continue;
                }

                try {
                    // Dibungkus jadi bentuk payload supaya memakai parser yang
                    // sama persis dengan jalur webhook. Satu tempat saja yang
                    // tahu cara membaca format Fingerspot.
                    $scans = $this->parser->extractScans(['data' => [$row]]);
                } catch (InvalidScanPayload $e) {
                    // Satu baris rusak tidak boleh menggagalkan seluruh tarikan.
                    $hasil['invalid']++;

                    Log::warning('Baris get_attlog dilewati.', [
                        'error' => $e->getMessage(),
                        'row' => $row,
                    ]);

                    continue;
                }

                $ditulis = $this->parser->storeScans($scans, (string) $cloudId, 'sync');

                $hasil['created'] += $ditulis['created'];
                $hasil['duplicate'] += $ditulis['duplicate'];
            }
        }

        return $hasil;
    }

    /**
     * Potong rentang jadi jendela sesuai batas maksimal per permintaan.
     *
     * Dokumentasi membatasi satu request maksimal 2 hari inklusif, jadi
     * menarik seminggu berarti empat permintaan terpisah.
     *
     * @return \Generator<int, array{0: Carbon, 1: Carbon}>
     */
    public function chunks(Carbon $from, Carbon $to): \Generator
    {
        $timezone = config('attendance.timezone', 'Asia/Jakarta');

        $mulai = $from->copy()->setTimezone($timezone)->startOfDay();
        $akhir = $to->copy()->setTimezone($timezone)->startOfDay();

        if ($mulai->greaterThan($akhir)) {
            throw new FingerspotException('Tanggal awal tidak boleh melewati tanggal akhir.');
        }

        // Jangan meminta data yang memang sudah dihapus Fingerspot. Lebih baik
        // mundur ke batas retensi daripada dapat error dari server.
        $batasRetensi = Carbon::today($timezone)->subDays((int) config('fingerspot.retention_days', 60));

        if ($mulai->lessThan($batasRetensi)) {
            $mulai = $batasRetensi->copy();
        }

        $maks = max(1, (int) config('fingerspot.max_days_per_request', 2));

        while ($mulai->lessThanOrEqualTo($akhir)) {
            $ujung = $mulai->copy()->addDays($maks - 1);

            if ($ujung->greaterThan($akhir)) {
                $ujung = $akhir->copy();
            }

            yield [$mulai->copy(), $ujung->copy()];

            $mulai = $ujung->addDay();
        }
    }
}
