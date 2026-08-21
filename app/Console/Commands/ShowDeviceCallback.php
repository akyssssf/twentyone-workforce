<?php

namespace App\Console\Commands;

use App\Models\DeviceCallback;
use Illuminate\Console\Command;

/**
 * Lihat jawaban yang dikirim balik mesin ke webhook.
 *
 * Perintah asinkron (`set_userinfo`, `get_userinfo`, `reg_online`) tidak pernah
 * mengembalikan hasilnya di respons HTTP — hasilnya datang belakangan sebagai
 * callback. Tanpa cara membacanya, satu-satunya jawaban yang bisa diberikan
 * saat mesin telat menjawab adalah "tidak tahu", dan itu membuat seluruh alur
 * asinkron tidak bisa didiagnosis sama sekali.
 *
 * Tanpa argumen, perintah ini juga menjawab pertanyaan yang lebih mendasar:
 * apakah webhook-nya hidup? Kalau callback terakhir yang pernah masuk sudah
 * berhari-hari lalu, yang rusak bukan pendaftaran wajahnya.
 */
class ShowDeviceCallback extends Command
{
    protected $signature = 'fingerspot:callback
                            {trans_id? : trans_id yang dicari, kosong berarti tampilkan yang terbaru}
                            {--jumlah=10 : Berapa callback terbaru yang ditampilkan}';

    protected $description = 'Lihat callback yang dikirim mesin Fingerspot ke webhook';

    public function handle(): int
    {
        $transId = $this->argument('trans_id');

        return $transId === null
            ? $this->tampilkanTerbaru()
            : $this->cari((string) $transId);
    }

    protected function cari(string $transId): int
    {
        $callback = DeviceCallback::where('trans_id', $transId)->latest('id')->first();

        if ($callback === null) {
            $this->error("Belum ada callback dengan trans_id {$transId}.");
            $this->newLine();
            $this->line('Itu berarti salah satu dari ini:');
            $this->line('  - mesin belum sempat menjalankan perintahnya (coba lagi nanti),');
            $this->line('  - mesin sedang mati atau tidak terhubung internet,');
            $this->line('  - webhook_url di perangkat tidak menunjuk ke server ini.');
            $this->newLine();
            $this->line('Cek dua kemungkinan terakhir dengan <info>php artisan fingerspot:check</info>,');
            $this->line('dan lihat apakah webhook hidup dengan <info>php artisan fingerspot:callback</info> tanpa argumen.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("<comment>Callback {$transId}</comment>");
        $this->line('  diterima : '.$callback->received_at?->format('Y-m-d H:i:s'));
        $this->line('  type     : '.($callback->type ?? '-'));
        $this->line('  cloud_id : '.($callback->cloud_id ?? '-'));

        $status = (string) data_get($callback->payload, 'data.status', '');

        $this->line('  status   : '.match ($status) {
            '1' => '<info>1 (sukses)</info>',
            '2' => '<fg=red>2 (gagal dieksekusi mesin)</>',
            '' => '(tidak ada field status)',
            default => $status,
        });

        $this->newLine();
        $this->line('  isi lengkap: '.json_encode($callback->payload));
        $this->newLine();

        return $status === '1' ? self::SUCCESS : self::FAILURE;
    }

    protected function tampilkanTerbaru(): int
    {
        $jumlah = max(1, (int) $this->option('jumlah'));

        $callbacks = DeviceCallback::latest('id')->limit($jumlah)->get();

        if ($callbacks->isEmpty()) {
            $this->error('Belum pernah ada satu pun callback masuk.');
            $this->line('Webhook-nya belum pernah dipakai mesin — periksa <info>php artisan fingerspot:check</info>.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Diterima', 'Type', 'trans_id', 'Ringkas'],
            $callbacks->map(fn (DeviceCallback $c) => [
                $c->received_at?->format('Y-m-d H:i:s') ?? '-',
                $c->type ?? '-',
                $c->trans_id ?? '—',
                $this->ringkas($c),
            ])->all(),
        );

        $this->newLine();

        return self::SUCCESS;
    }

    protected function ringkas(DeviceCallback $callback): string
    {
        $data = $callback->payload['data'] ?? [];

        // Scan spontan tidak punya trans_id dan bentuk datanya berbeda dari
        // jawaban perintah, jadi diringkas dengan cara masing-masing.
        if (isset($data['scan'])) {
            return 'pin '.($data['pin'] ?? '?').', scan '.$data['scan'];
        }

        return isset($data['status'])
            ? 'status '.$data['status'].($data['status'] === '1' ? ' (sukses)' : ' (gagal)')
            : '-';
    }
}
