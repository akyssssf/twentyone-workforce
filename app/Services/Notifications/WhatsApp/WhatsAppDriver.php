<?php

namespace App\Services\Notifications\WhatsApp;

/**
 * Satu-satunya hal yang perlu diketahui aplikasi tentang WhatsApp: kirim teks
 * ke satu nomor, berhasil atau tidak.
 *
 * Antarmuka sesempit ini disengaja. Gateway WhatsApp di Indonesia sering
 * berganti — yang hari ini murah bisa tutup tahun depan — dan penggantian
 * seharusnya cukup menambah satu kelas, bukan menyentuh modul pengajuan.
 */
interface WhatsAppDriver
{
    /**
     * @param  string  $nomor  Sudah dinormalkan ke bentuk 628xxxxxxxxx.
     *
     * @throws \RuntimeException kalau gateway menolak atau tidak bisa dihubungi.
     */
    public function send(string $nomor, string $pesan): void;
}
