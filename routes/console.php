<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Jadwal
|--------------------------------------------------------------------------
|
| Butuh satu entri cron di server:
|
|   * * * * * cd /path/ke/project && php artisan schedule:run >> /dev/null 2>&1
|
*/

// Kuras antrian callback jadi attendance_logs.
//
// withoutOverlapping mencegah dua proses menggarap antrian yang sama saat
// jalannya kebetulan lebih lama dari satu menit. Tanpa ini keduanya akan
// saling berebut dan sebagian besar kerjanya terbuang jadi duplikat yang
// ditolak. runInBackground supaya tidak menahan tugas terjadwal lain.
Schedule::command('attendance:parse-callbacks')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Hitung ulang rekap dua hari terakhir.
//
// Kemarin ikut dihitung ulang, bukan hanya hari ini, karena scan pulang
// Shift 2 baru lengkap setelah lewat tengah malam. Tiap 15 menit sudah cukup
// segar buat dashboard tanpa membebani apa pun.
Schedule::command('attendance:compute')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Jalur cadangan: tarik ulang dua hari terakhir dari Fingerspot.
//
// Jam 02:00 dipilih karena saat itu Shift 2 sudah benar-benar bubar (pulang
// 01:00), jadi hari kemarin sudah lengkap. Scan yang webhook-nya kelewat
// ditambal di sini, dan yang sudah masuk ditolak unique constraint jadi tidak
// ada risiko dobel.
Schedule::command('attendance:sync --days=2')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();

// Salinan database tiap hari jam 03:00.
//
// Jam 03:00 dipilih karena shift malam sudah bubar (pulang 01:00) dan sinkron
// cadangan get_attlog jam 02:00 sudah selesai — jadi salinannya memuat hari
// kemarin secara utuh.
Schedule::command('db:backup')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

// Tutup hari kemarin jam 06:00.
//
// Jendela shift malam baru benar-benar tutup jam 05:00, jadi sebelum itu
// "tidak ada scan" belum tentu berarti alpha.
Schedule::command('attendance:compute --days=2')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->runInBackground();
