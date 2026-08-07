<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Driver pengirim
    |--------------------------------------------------------------------------
    |
    | log    : tidak mengirim apa pun, cuma menulis ke storage/logs. Dipakai
    |          saat mengembangkan dan saat menguji — supaya tidak ada pesan
    |          nyasar ke nomor karyawan sungguhan saat mencoba-coba.
    | fonnte : gateway WhatsApp lokal (fonnte.com). Paling sederhana untuk
    |          kafe: daftar, sambungkan nomor lewat QR, salin token.
    | cloud  : WhatsApp Cloud API resmi Meta. Lebih tahan lama tapi butuh akun
    |          bisnis terverifikasi dan template pesan yang disetujui dulu.
    |
    | Bawaannya `log`. Salah kirim ke nomor asli lebih mahal daripada tidak
    | terkirim sama sekali, jadi pengiriman sungguhan harus dinyalakan sengaja.
    |
    */

    'driver' => env('WHATSAPP_DRIVER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Nomor admin
    |--------------------------------------------------------------------------
    |
    | Tujuan pemberitahuan yang ditujukan ke pengelola, dan nomor yang muncul
    | di tombol "Konfirmasi lewat WhatsApp" di sisi karyawan.
    |
    | Nilai ini juga tersimpan di tabel settings supaya bisa diubah dari UI;
    | yang di sini jadi cadangan kalau setelannya belum ada.
    |
    */

    'admin_number' => env('WHATSAPP_ADMIN_NUMBER', '6285876163554'),

    /*
    |--------------------------------------------------------------------------
    | Fonnte
    |--------------------------------------------------------------------------
    */

    'fonnte' => [
        'url' => env('FONNTE_URL', 'https://api.fonnte.com/send'),
        'token' => env('FONNTE_TOKEN'),

        // Jeda antar pesan (detik). Gateway gratisan sering membatasi laju
        // kirim, dan pesan yang ditolak karena terlalu cepat tidak dikirim
        // ulang oleh mereka.
        'delay' => env('FONNTE_DELAY', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API (Meta)
    |--------------------------------------------------------------------------
    */

    'cloud' => [
        'version' => env('WHATSAPP_CLOUD_VERSION', 'v21.0'),
        'phone_number_id' => env('WHATSAPP_CLOUD_PHONE_ID'),
        'token' => env('WHATSAPP_CLOUD_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Percobaan ulang
    |--------------------------------------------------------------------------
    |
    | Jaringan di kafe sering putus sebentar. Pesan yang gagal dicoba lagi
    | beberapa kali sebelum ditandai gagal permanen di notification_deliveries.
    |
    */

    'max_attempts' => env('WHATSAPP_MAX_ATTEMPTS', 3),

];
