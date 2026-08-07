<?php

namespace App\Services\Notifications\WhatsApp;

use App\Support\PhoneNumber;
use RuntimeException;

/**
 * Pemilih driver + penjaga sebelum kirim.
 *
 * Semua pengecekan yang tidak ada urusannya dengan gateway tertentu berhenti di
 * sini: nomor kosong, nomor tidak masuk akal, driver belum diatur. Driver cuma
 * mengurus cara bicara dengan gateway-nya.
 */
class WhatsAppManager
{
    public function driver(?string $nama = null): WhatsAppDriver
    {
        $nama ??= config('whatsapp.driver', 'log');

        return match ($nama) {
            'fonnte' => new FonnteDriver,
            'cloud' => new CloudApiDriver,
            'log' => new LogDriver,
            default => throw new RuntimeException("Driver WhatsApp \"{$nama}\" tidak dikenal."),
        };
    }

    public function aktif(): bool
    {
        return config('whatsapp.driver', 'log') !== 'log';
    }

    /**
     * @throws RuntimeException
     */
    public function send(?string $nomor, string $pesan): void
    {
        $tujuan = PhoneNumber::normalize($nomor);

        if ($tujuan === null) {
            throw new RuntimeException('Nomor WhatsApp kosong.');
        }

        // Nomor Indonesia yang sah panjangnya 10-15 digit setelah dinormalkan.
        // Yang di luar itu hampir pasti salah ketik, dan mengirimkannya berarti
        // pesan berisi kode lembur nyasar ke orang asing.
        if (! preg_match('/^\d{10,15}$/', $tujuan)) {
            throw new RuntimeException("Nomor WhatsApp tidak masuk akal: {$tujuan}");
        }

        $this->driver()->send($tujuan, $pesan);
    }
}
