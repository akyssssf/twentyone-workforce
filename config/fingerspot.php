<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kredensial API Fingerspot
    |--------------------------------------------------------------------------
    |
    | Dipakai jalur cadangan (cron harian get_attlog). Belum dipanggil di tahap
    | ini, tapi ditaruh sekarang biar konfigurasinya sudah satu tempat.
    |
    */

    'api_url' => rtrim(env('FINGERSPOT_API_URL', 'https://developer.fingerspot.io/api'), '/'),

    'api_token' => env('FINGERSPOT_API_TOKEN'),

    'cloud_id' => env('FINGERSPOT_CLOUD_ID'),

    /*
    |--------------------------------------------------------------------------
    | Secret webhook
    |--------------------------------------------------------------------------
    |
    | Fingerspot tidak mengirim signature apa pun, jadi satu-satunya proteksi
    | adalah secret yang ditanam di segmen URL. Dicek pakai hash_equals supaya
    | tidak bocor lewat timing attack.
    |
    */

    'webhook_secret' => env('FINGERSPOT_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Batasan get_attlog
    |--------------------------------------------------------------------------
    |
    | Sesuai dokumentasi: data tersimpan sampai 60 hari ke belakang, dan satu
    | request maksimal mencakup 2 hari (start_date s/d end_date inklusif).
    |
    */

    'retention_days' => 60,

    'max_days_per_request' => 2,

    /*
    |--------------------------------------------------------------------------
    | Kamus kode mesin
    |--------------------------------------------------------------------------
    |
    | Referensi kode yang dikirim mesin. Mesin selain seri Fingerspot bisa
    | mengirim kode di luar daftar ini, jadi jangan dipakai buat validasi
    | ketat, cukup buat pelabelan di laporan.
    |
    */

    'verify_modes' => [
        1 => 'sidik_jari',
        2 => 'password',
        3 => 'kartu',
        4 => 'wajah',
        6 => 'vein',
        7 => 'qr',
    ],

    'status_scans' => [
        0 => 'scan_masuk',
        1 => 'scan_keluar',
        2 => 'istirahat_masuk',
        3 => 'istirahat_keluar',
        4 => 'lembur_masuk',
        5 => 'lembur_keluar',
        6 => 'rapat_masuk',
        7 => 'rapat_keluar',
        8 => 'kustom1',
        9 => 'kustom2',
    ],

];
