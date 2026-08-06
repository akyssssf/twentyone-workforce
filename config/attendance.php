<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Timezone operasional
    |--------------------------------------------------------------------------
    |
    | Mesin Fingerspot mengirim waktu lokal tanpa offset, jadi seluruh string
    | jam dari mesin diperlakukan sebagai waktu di zona ini.
    |
    */

    'timezone' => 'Asia/Jakarta',

    /*
    |--------------------------------------------------------------------------
    | Aturan keterlambatan
    |--------------------------------------------------------------------------
    |
    | grace_seconds  : toleransi telat. Nol berarti lewat satu detik dari
    |                  scheduled_in sudah dihitung telat.
    | block_minutes  : besar satu blok potongan.
    | block_deduction: nominal rupiah potongan per blok.
    | round_up       : true berarti kelebihan sedikit tetap dihitung satu blok
    |                  penuh (telat 1 detik = 1 blok). Kalau nanti mau telat
    |                  11 menit dihitung 1 blok saja, ubah ke false.
    |
    | Contoh dengan setelan bawaan (blok 10 menit, Rp5.000):
    |   telat 1 detik   -> 1 blok  -> Rp 5.000
    |   telat 10 menit  -> 1 blok  -> Rp 5.000
    |   telat 10 m 1 dt -> 2 blok  -> Rp 10.000
    |
    */

    'late' => [
        'grace_seconds' => (int) env('ATTENDANCE_LATE_GRACE_SECONDS', 0),
        'block_minutes' => (int) env('ATTENDANCE_LATE_BLOCK_MINUTES', 10),
        'block_deduction' => (int) env('ATTENDANCE_LATE_BLOCK_DEDUCTION', 5000),
        'round_up' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Jendela kerja
    |--------------------------------------------------------------------------
    |
    | Menentukan scan mana yang dianggap milik satu work_date. Tidak bisa
    | sekadar "semua scan di tanggal kalender yang sama", karena Shift 2
    | berjalan 17:00 sampai 01:00 dan melewati tengah malam: scan pulangnya
    | jatuh di tanggal berikutnya.
    |
    | Jendela dihitung dari jam shift:
    |   mulai   = jam masuk shift dikurangi before_shift_hours
    |   selesai = jam pulang shift ditambah after_shift_hours
    |             (kalau jam pulang <= jam masuk, dianggap hari berikutnya)
    |
    | Contoh Shift 2 pada work_date 2026-08-06:
    |   jendela = 2026-08-06 13:00  s/d  2026-08-07 05:00
    |
    | before_shift_hours juga menentukan seberapa awal seseorang boleh datang
    | dan tetap terhitung di hari itu. Empat jam cukup longgar buat kafe tanpa
    | menabrak jendela shift sebelumnya.
    |
    */

    'window' => [
        'before_shift_hours' => 4,
        'after_shift_hours' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Penentuan jam masuk dan jam pulang
    |--------------------------------------------------------------------------
    |
    | earliest_latest : scan paling awal di jendela jadi jam masuk, paling
    |                   akhir jadi jam pulang.
    | status_scan     : ikuti kode tombol mesin (0=masuk, 1=keluar).
    |
    | Bawaannya earliest_latest karena di kafe orang jarang menekan tombol
    | fungsi sebelum menempelkan jari, sehingga status_scan hampir selalu
    | bernilai bawaan mesin dan tidak bisa dipercaya. Ubah ke status_scan
    | hanya kalau karyawan benar-benar dilatih menekan tombolnya.
    |
    */

    'check_in_out_strategy' => env('ATTENDANCE_INOUT_STRATEGY', 'earliest_latest'),

    /*
    |--------------------------------------------------------------------------
    | Status hasil olahan
    |--------------------------------------------------------------------------
    */

    'statuses' => [
        'hadir' => 'hadir',
        'telat' => 'telat',
        'alpha' => 'alpha',
        'libur' => 'libur',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sumber data attendance_logs
    |--------------------------------------------------------------------------
    |
    | webhook = didorong mesin realtime, sync = hasil tarikan cron get_attlog.
    |
    */

    'sources' => [
        'webhook' => 'webhook',
        'sync' => 'sync',
    ],

];
