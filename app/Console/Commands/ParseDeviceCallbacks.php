<?php

namespace App\Console\Commands;

use App\Models\DeviceCallback;
use App\Services\Fingerspot\AttlogParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Penguras antrian callback yang belum dipindahkan ke attendance_logs.
 *
 * Sengaja memakai scheduler, bukan queue worker. Cron `schedule:run` toh sudah
 * wajib ada untuk jalur cadangan get_attlog harian, jadi menumpang di situ
 * tidak menambah proses yang harus dijaga hidup. Untuk beban satu kafe,
 * jeda paling lama satu menit sama sekali tidak terasa.
 */
class ParseDeviceCallbacks extends Command
{
    protected $signature = 'attendance:parse-callbacks
                            {--limit=500 : Jumlah callback maksimal per jalan}';

    protected $description = 'Ubah callback mentah Fingerspot jadi baris attendance_logs';

    public function handle(AttlogParser $parser): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $callbacks = DeviceCallback::unparsed()->limit($limit)->get();

        if ($callbacks->isEmpty()) {
            $this->info('Tidak ada callback yang menunggu.');

            return self::SUCCESS;
        }

        $created = 0;
        $duplicate = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($callbacks as $callback) {
            try {
                $result = $parser->parseCallback($callback);

                $created += $result['created'];
                $duplicate += $result['duplicate'];
                $skipped += (int) $result['skipped'];
            } catch (\Throwable $e) {
                // Kegagalan sementara. parsed dibiarkan false supaya callback
                // ini ikut lagi di putaran berikutnya, dan satu callback
                // bermasalah tidak menghentikan sisa antrian.
                $failed++;

                Log::error('Gagal memproses callback Fingerspot.', [
                    'device_callback_id' => $callback->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf(
            'Callback diproses: %d. Log baru: %d, duplikat dilewati: %d, dilewati: %d, gagal: %d.',
            $callbacks->count(), $created, $duplicate, $skipped, $failed,
        ));

        // Kode keluar tetap SUCCESS. Kegagalan sebagian sudah tercatat di log
        // dan otomatis dicoba lagi, jadi tidak perlu bikin cron ribut.
        return self::SUCCESS;
    }
}
