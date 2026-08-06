<?php

namespace App\Console\Commands;

use App\Services\Fingerspot\FingerspotClient;
use App\Services\Fingerspot\FingerspotException;
use Illuminate\Console\Command;

/**
 * Pemeriksa kesehatan konfigurasi Fingerspot.
 *
 * Memakai get_device karena sinkron dan read-only, jadi aman dijalankan
 * berulang kali tanpa efek samping ke mesin.
 */
class CheckFingerspot extends Command
{
    protected $signature = 'fingerspot:check';

    protected $description = 'Uji token dan cloud_id Fingerspot, lalu tampilkan webhook yang terdaftar';

    public function handle(FingerspotClient $client): int
    {
        $this->info('Menghubungi Fingerspot...');

        try {
            $device = $client->getDevice();
        } catch (FingerspotException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('<info>Token diterima.</info> Perangkat terbaca:');
        $this->newLine();

        $this->table(['Properti', 'Nilai'], [
            ['cloud_id', $device['cloud_id'] ?? '-'],
            ['device_name', $device['device_name'] ?? '-'],
            ['webhook_url', $device['webhook_url'] ?? '-'],
            ['created_at', $device['created_at'] ?? '-'],
            ['last_activity', $device['last_activity'] ?? '-'],
        ]);

        $this->reviewWebhookUrl((string) ($device['webhook_url'] ?? ''));

        return self::SUCCESS;
    }

    /**
     * Bandingkan webhook terdaftar dengan endpoint aplikasi ini. Salah alamat
     * di sini berarti seluruh jalur utama mati tanpa error apa pun, jadi lebih
     * baik ketahuan sekarang.
     */
    protected function reviewWebhookUrl(string $registered): void
    {
        $secret = (string) config('fingerspot.webhook_secret');
        $expected = $secret !== '' ? route('fingerspot.webhook', ['secret' => $secret]) : null;

        $this->newLine();

        if ($registered === '') {
            $this->warn('Perangkat belum punya webhook_url. Scan tidak akan terkirim ke mana pun.');
        } elseif (str_contains($registered, 'webhook.site')) {
            $this->warn('Webhook masih mengarah ke webhook.site, belum ke aplikasi ini.');
        } elseif ($expected !== null && $registered === $expected) {
            $this->info('Webhook sudah mengarah ke aplikasi ini.');

            return;
        } else {
            $this->warn('Webhook terdaftar tidak sama dengan endpoint aplikasi ini.');
        }

        if ($expected !== null) {
            $this->newLine();
            $this->line('Isi dengan URL berikut di portal pelanggan Fingerspot:');
            $this->line("  <comment>{$expected}</comment>");

            if (str_contains($expected, 'localhost') || str_contains($expected, '127.0.0.1')) {
                $this->newLine();
                $this->warn('APP_URL masih localhost. Mesin tidak bisa menjangkau alamat ini,');
                $this->warn('jadi setel APP_URL ke domain publik dulu sebelum mendaftarkannya.');
            }
        } else {
            $this->error('FINGERSPOT_WEBHOOK_SECRET belum diisi, URL webhook tidak bisa dibentuk.');
        }
    }
}
