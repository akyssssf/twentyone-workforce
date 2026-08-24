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
    | Ambang pergantian hari di dashboard
    |--------------------------------------------------------------------------
    |
    | "Hari Ini" di dashboard & beranda karyawan tidak pindah tanggal persis
    | tengah malam — baru pindah begitu lewat jam ini. Shift malam berakhir
    | 01:00 dan jendela absensinya baru benar-benar tutup 05:00 (lihat
    | window.after_shift_hours), jadi sebelum jam segini "hari ini" yang
    | sebenarnya masih relevan justru tanggal kemarin. Lihat App\Support\OperationalDate.
    |
    | Sengaja disamakan dengan jam cron attendance:compute --days=2 di
    | routes/console.php, bukan angka baru — satu konvensi, bukan dua.
    |
    */

    'dashboard_cutover_hour' => (int) env('ATTENDANCE_DASHBOARD_CUTOVER_HOUR', 6),

    /*
    |--------------------------------------------------------------------------
    | Tanggal mulai pelacakan
    |--------------------------------------------------------------------------
    |
    | Tanggal sebelum ini tidak pernah dihitung sama sekali — bukan cuma
    | disembunyikan, tapi computeDate() melewatinya begitu saja, persis
    | seperti penjagaan joined_at per karyawan tapi berlaku untuk seluruh
    | perusahaan. Dipakai juga sebagai batas bawah periode di rekap bulanan.
    |
    | Alasannya: mesin baru terpasang beberapa hari lalu, dan sebelum tanggal
    | ini "tidak ada scan" bukan berarti alpha — ya iyalah, mesinnya memang
    | belum ada. Null berarti tidak ada batas.
    |
    */

    'tracking_starts_on' => env('ATTENDANCE_TRACKING_STARTS_ON'),

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
    | Jendela tangkap jam pulang
    |--------------------------------------------------------------------------
    |
    | Dipakai strategi earliest_latest saja. Scan terakhir di hari itu baru
    | dianggap jam pulang kalau jaraknya ke scheduled_out tidak lebih dari
    | sekian menit ini. Tanpa batas ini, scan nyasar di tengah shift (orang
    | lewat depan kamera, ketiban wajah orang lain, dsb) bisa terbaca sebagai
    | scan pulang paling akhir dan menuduh pulang cepat berjam-jam padahal
    | orangnya masih kerja. Kalau tidak ada scan dalam jendela ini,
    | check_out_at dibiarkan null — sama seperti lupa fingerprint, tidak kena
    | potongan pulang cepat.
    |
    | Scan setelah scheduled_out tetap diterima tanpa batas atas (dibatasi
    | window_after_hours milik shift), dan tidak otomatis jadi lembur —
    | lembur cuma dari approval (BR-14).
    |
    */

    'checkout_capture_minutes' => (int) env('ATTENDANCE_CHECKOUT_CAPTURE_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Tebak shift dari jam scan (tanpa roster)
    |--------------------------------------------------------------------------
    |
    | Dipakai kalau karyawan belum punya RosterAssignment untuk tanggal itu.
    | Shift yang dipilih adalah yang jam mulainya paling dekat dengan scan
    | pertama, jadi batasnya ikut jam shift di master data dan tidak perlu
    | diatur ulang setiap kali shift baru ditambahkan.
    |
    | detection_start_hour : mulai cari scan pertama dari jam berapa. Sebelum
    |                        jam ini diabaikan supaya scan pulang shift malam
    |                        kemarin (lewat tengah malam) tidak ikut kehitung.
    |
    */

    'shift_guess' => [
        'detection_start_hour' => (int) env('ATTENDANCE_SHIFT_GUESS_START_HOUR', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Libur yang dipilih sendiri karyawan
    |--------------------------------------------------------------------------
    |
    | Posisi tertentu (Logistik) masuk hampir setiap hari dan jatah liburnya
    | dihitung per bulan, bukan per minggu — dan tanggalnya dipilih sendiri oleh
    | orangnya, bukan ditetapkan di roster.
    |
    | divisi        : kode divisi yang boleh memakai jalur ini. Sengaja daftar,
    |                 bukan satu nilai, supaya menambah divisi lain nanti tidak
    |                 perlu menyentuh kode.
    | jatah_per_bulan: berapa hari libur yang boleh dipilih dalam satu bulan
    |                 kalender. Pilihan yang melebihi ini DITOLAK, bukan sekadar
    |                 diperingatkan.
    | source        : penanda asal baris roster untuk libur pilihan sendiri.
    |                 Dipakai menghitung jatah terpakai, sehingga libur yang
    |                 dipasang admin lewat roster:set tidak ikut memotong jatah
    |                 orangnya.
    |
    */

    'libur_pilihan' => [
        'divisi' => ['logistik'],
        'jatah_per_bulan' => (int) env('ATTENDANCE_LIBUR_PILIHAN_PER_BULAN', 2),
        'source' => 'pilihan',
    ],

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
