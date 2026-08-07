<?php

namespace App\Services\Notifications\WhatsApp;

use Illuminate\Support\Facades\Log;

/**
 * Tidak mengirim apa pun, cuma mencatat.
 *
 * Ini driver bawaan, dan itu disengaja: pesan nyasar ke nomor karyawan
 * sungguhan saat mencoba-coba jauh lebih mahal daripada pesan yang tidak
 * terkirim. Pengiriman sungguhan harus dinyalakan sengaja lewat
 * WHATSAPP_DRIVER.
 */
class LogDriver implements WhatsAppDriver
{
    public function send(string $nomor, string $pesan): void
    {
        Log::channel(config('logging.default'))->info('[WhatsApp: tidak dikirim]', [
            'tujuan' => $nomor,
            'pesan' => $pesan,
        ]);
    }
}
