# Absensi Kafe

Sistem absensi dan penjadwalan untuk 21 Kafe, dari scan sidik jari di mesin
Fingerspot sampai rekap yang siap dipakai hitung gajian.

## Cara kerjanya

Mesin Fingerspot mengirim setiap scan ke aplikasi (lewat webhook, ditambal cron
tarikan berkala kalau ada yang kelewat). Scan mentah disimpan apa adanya, lalu
diolah jadi rekap harian oleh `AttendanceComputer`.

Yang penting dipahami: **tabel `attendances` sepenuhnya turunan.** Isinya boleh
dikosongkan dan dibangun ulang kapan saja tanpa kehilangan apa pun — termasuk
keputusan manager, karena koreksi hidup di tabelnya sendiri sebagai input, bukan
sebagai hasil yang bisa tertimpa. Kalau angkanya terlihat meleset, jalankan
ulang `attendance:compute`.

Sumber kebenaran "hari ini dia shift apa" adalah **roster**, bukan
`employees.default_shift_id`. Kolom itu cuma preferensi untuk generator roster,
dan jalur cadangan menebak shift dari jam scan selama roster belum diisi.

## Menjalankan secara lokal

```bash
composer setup
php artisan serve
```

`composer setup` menyalin `.env`, membuat kunci aplikasi, menjalankan migrasi,
lalu membangun aset frontend.

## Perintah yang sering dipakai

```bash
php artisan attendance:compute --from=2026-08-01 --to=2026-08-31
php artisan attendance:status
php artisan employee:list
php artisan holiday list
php artisan db:backup
```

`attendance:compute` aman dijalankan berkali-kali: tiap tanggal dihitung ulang
dari nol, jadi hasilnya selalu sama untuk data yang sama.

## Tugas terjadwal

Cron memanggil `schedule:run-inline` tiap menit, bukan `schedule:run` bawaan
Laravel. Alasannya ada di kelasnya: scheduler bawaan selalu men-spawn tiap
perintah lewat `proc_open`, dan banyak hosting bersama mematikan fungsi itu.
Kalau jadwal produksi berubah, ubah juga daftarnya di `RunScheduleInline`.

## Tes

```bash
composer test
```
