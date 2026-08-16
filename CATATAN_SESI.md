# Catatan Konteks Proyek — 21 Kafe

Dokumen ini dibuat supaya AI/developer lain yang lanjut kerja di proyek ini
tidak perlu menemukan ulang apa yang sudah ditemukan di sesi sebelumnya —
terutama jebakan-jebakan yang sudah pernah bikin bug produksi. Ditulis
16 Agustus 2026, setelah maraton perbaikan dari cabang `feat/hris-lengkap`
(sudah menyatu ke `main`).

Kalau ada yang di sini ternyata sudah berubah (kode sudah direfactor, bug
sudah dibenerin ulang, dst), percayai kode yang ada sekarang — dokumen ini
snapshot per tanggal di atas, bukan kebenaran abadi.

---

## 1. Apa proyek ini

Sistem absensi & penjadwalan untuk **21 Kafe**, satu cabang, ~18-20
karyawan. Laravel 13, SQLite, di-hosting di **Hostinger shared hosting**.
Absensi masuk lewat mesin fingerprint Fingerspot (webhook + sync cron),
lalu `AttendanceComputer` mengubahnya jadi rekap harian berdasarkan
**roster** (jadwal shift per hari, sumber kebenaran tunggal — bukan
`employees.default_shift_id`, itu cuma preferensi/fallback).

Alur inti: `attendance_logs` (scan mentah) → `AttendanceComputer` →
`attendances` (rekap turunan, boleh dihapus & dibangun ulang kapan saja)
→ `MonthlyReport` (laporan) → payroll (belum pernah dijalankan sampai
tanggal dokumen ini ditulis).

## 2. Lingkungan & deploy — PENTING, banyak jebakan di sini

**Hostinger mematikan `proc_open` DAN `shell_exec`** di kontainer build/cron
mereka. Ini bikin dua hal harus dikerjakan beda dari Laravel standar:

- **Deploy**: `composer install` gagal kalau `composer.json` masih punya
  hook `post-autoload-dump` yang manggil `@php artisan package:discover`
  (subprocess = butuh `proc_open`). Sudah dihapus dari hook itu — Laravel
  membangun ulang `bootstrap/cache/packages.php` sendiri saat boot pertama
  kalau berkasnya tidak ada, jadi aman dihapus dari hook otomatis.
- **Scheduler**: `schedule:run` bawaan Laravel selalu men-spawn tiap command
  lewat Symfony Process (`proc_open`), jadi **tidak dipakai**. Sebagai
  gantinya ada `App\Console\Commands\RunScheduleInline` (`schedule:run-inline`)
  yang menjalankan `Artisan::call()` langsung di proses yang sama. Daftar
  tugas & jadwalnya **disalin manual** di kelas itu (bukan baca otomatis
  dari `routes/console.php`) — kalau jadwal produksi berubah, dua tempat itu
  harus diubah bareng.
- **Cron di server**: `crontab -e`/`crontab -` **diblokir** untuk akun ini.
  Cron harus diatur lewat menu **Cron Jobs di hPanel**, bukan SSH. Sudah ada
  satu entri terpasang: `* * * * * php .../artisan schedule:run-inline`.
  Cek jalan-tidaknya lewat `cat storage/framework/schedule-run-inline.heartbeat`.

**Ada DUA folder Laravel di server**, ini pernah bikin bingung berjam-jam:

- `/home/u875156262/domains/twentyonecafe.id/public_html` — **folder yang
  benar-benar live** (yang diakses browser).
- `/home/u875156262/domains/twentyonecafe.id/public_html/kafe` — folder
  auto-deploy Hostinger yang **salah sasaran** (tidak pernah disajikan ke
  web). Kalau nemu folder ini kosongin curiga dulu, jangan asumsikan itu
  yang live.

Karena auto-deploy nyasar ke `kafe/`, **`git pull` di folder yang benar
harus dijalankan manual tiap kali ada perubahan kode**, tidak otomatis.
Belum sempat dibenerin permanen (perlu masuk ke pengaturan Git di hPanel).

**Timezone & "hari ini"**: server jalan di UTC, aplikasi pakai `Asia/Jakarta`
(WIB, +7). "Hari ini" di dashboard/beranda **tidak** ganti tepat tengah
malam — baru ganti jam **06:00 WIB** (`ATTENDANCE_DASHBOARD_CUTOVER_HOUR`,
lihat `App\Support\OperationalDate`), supaya shift malam yang masih jalan
lewat tengah malam tidak keputus. Kalau lihat data "aneh" dini hari,
cek dulu jam WIB-nya sebelum curiga bug.

## 3. Konvensi kode (biar konsisten)

- Nama variabel/fungsi/komentar **Bahasa Indonesia**, nama kelas/method
  Laravel-standar tetap Inggris.
- Komentar isinya jelasin **KENAPA**, bukan APA — terutama kalau itu jebakan
  yang sudah pernah bikin bug (banyak contoh di `AttendanceComputer.php`,
  `RosterService.php`).
- `git commit` selalu pakai `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`
  atau model yang sedang dipakai.
- Alur kerja tiap perubahan: edit → `php artisan test` (full suite) →
  `./vendor/bin/pint --test` (cek file yang disentuh saja, banyak file lama
  yang sudah gagal pint dari sebelumnya — jangan dibenerin kalau bukan
  bagian dari perubahan) → commit ke `feat/hris-lengkap` → push → fast-forward
  merge ke `main` → push `main` (karena **`main` yang di-deploy Hostinger**)
  → kabari user buat `git pull` + migrate di server kalau ada migrasi baru.
- **Skrip database di produksi** dijalankan lewat berkas PHP standalone yang
  di-bootstrap manual (`require 'vendor/autoload.php'; ... $app->make(Kernel::class)->bootstrap();`)
  lewat `php /tmp/nama_skrip.php`, **bukan** `php artisan tinker --execute=...`
  — karena Tinker/PsyShell butuh `shell_exec` buat cek update manual saat
  start, dan itu juga dimatikan Hostinger.

## 4. Riwayat kerja sesi ini (kronologis, bug + akar masalah + solusi)

### 4.1 Karyawan tanpa roster jadi Alpha padahal belum pernah dijadwalkan
**Masalah**: sebelum roster diisi, siapa pun yang tidak scan otomatis dapat
status Alpha (tidak dibayar), padahal mereka memang belum pernah dikasih
jadwal resmi.
**Solusi (kebijakan sementara, disengaja)**: `AttendanceComputer::resolveStatus()`
— kalau `$assignment` (RosterAssignment asli) `null` dan tidak ada scan,
sekarang jadi **Libur**, bukan Alpha. Kalau ADA `RosterAssignment` asli
tapi tidak masuk, tetap Alpha seperti biasa. Ditandai `SEMENTARA` di
komentar kode — **harus dicabut** begitu roster dipakai penuh untuk semua
karyawan, karena Alpha jadi nyaris mustahil terjadi tanpa roster.

### 4.2 Tebak-shift mati diam-diam begitu shift ketiga (Middle) dibuat
**Masalah**: `guessShift()` di `AttendanceComputer` cuma jalan kalau
"persis 2 shift aktif". Begitu Shift Middle dibuat, syaratnya gagal terus,
semua orang tanpa roster jatuh ke `default_shift_id` — orang yang datang
13:53 untuk shift malam terbaca telat 354 menit atas shift pagi yang tidak
dia jalani.
**Solusi**: tebakan sekarang ambil shift dengan **jam mulai terdekat** dari
jam scan pertama, berapa pun jumlah shift aktifnya — tidak lagi dibatasi
harus persis 2.

### 4.3 Deploy Hostinger gagal karena `proc_open`/`shell_exec`
Lihat bagian 2 di atas. Dua perbaikan terpisah: `composer.json` (hook
`package:discover` dihapus) dan `schedule:run-inline` (pengganti scheduler
bawaan).

### 4.4 Rekap absensi: rentang custom, exclude akun non-absen, Excel dirapikan
- Tambah tampilan **Custom** (`MonthlyReport::forRange()`) — dulu cuma
  harian/mingguan/bulanan, padahal periode gajian sering tidak jatuh rapi
  di batas bulan.
- Admin/akun test (`tracks_attendance = false`) dikeluarkan dari
  `MonthlyReport::ringkasan()` — mereka bukan "belum ada datanya", tapi
  memang tidak pernah diabsen.
- Excel: merge judul yang kependekan, angka menit dibulatkan & dikasih
  satuan, status pakai label bukan value mentah, autofilter, kolom nama
  dikunci.

### 4.5 Bug ganda di `RosterService::assign()`
1. **Ganti shift menambah baris, bukan memindahkan** — `shift_key` ikut
   jadi bagian kunci unik (karena double-shift diizinkan), jadi mengubah
   shift seseorang meninggalkan baris lamanya nyantol → orang itu kelihatan
   dobel jadwal di hari yang sama, rekap absensi ikut dobel.
2. **`updateOrCreate` mencari pakai string tanggal** (`"Y-m-d"`) padahal
   kolomnya nyimpan `"Y-m-d 00:00:00"` — jadi tidak pernah ketemu barisnya,
   berubah jadi INSERT yang nabrak unique constraint.
**Solusi**: `assign()` sekarang eksplisit menghapus baris employee+tanggal
lain yang `shift_key`-nya beda (**kecuali** `source` = `leave`/`swap`, itu
keputusan manusia yang sudah disetujui, tidak boleh hilang diam-diam), dan
pencarian pakai `$date->copy()->startOfDay()` (Carbon), bukan string.

### 4.6 Lembur dirombak total: dari jam diketik manual → dihitung dari scan
**Sebelumnya**: admin ketik jam mulai & selesai lembur manual, lalu ketik
lagi menit realisasinya setelah kejadian — dua kali menebak untuk sesuatu
yang sudah terekam mesin.
**Sekarang**: admin cuma pilih **siapa** dan **tanggal**. Lembur selalu
**menyambung shift** orang itu (mulai tepat saat shift terjadwalnya
selesai) sampai kafe tutup (jam pulang shift paling malam hari itu).
Lamanya dihitung otomatis dari jam pulang terjadwal sampai scan terakhir
(`OvertimeResolver`). **Tanpa scan pulang = dihitung penuh sampai kafe
tutup** — pilihan sadar (supaya yang lembur tidak dirugikan lupa scan),
bukan bug. Koreksi manual manajer (`confirmed_by` terisi) **tidak pernah
ditimpa** hitungan ulang otomatis. Shift yang berakhir setelah tengah
malam (shift Malam, dan sekarang Middle) **tidak bisa ditugaskan lembur**
— tidak ada yang bisa disambung setelah kafe tutup.

Ditambah **jenis keperluan lembur** (`App\Enums\OvertimeOccasion`):
`Pengganti` (wajib tunjuk siapa yang digantikan), `LiveMusic`, `Nobar`,
`Acara`. Cuma `Pengganti` yang wajib isi kolom pengganti — live music/nobar
tidak menggantikan siapa pun.

**Bug turunan yang ketahuan pas nulis test**: penugasan lembur massal
(pilih banyak orang sekaligus di form admin) cuma jadi untuk **orang
pertama** — controller manggil `approve()` lagi padahal jalur manajer
sudah auto-approved sejak dibuat, lemparannya menghentikan seluruh
perulangan. Sudah dibenerin, ada test yang lewat form asli (bukan cuma
manggil service langsung) supaya kelas bug ini ketangkep lagi kalau
muncul.

### 4.7 Dua bug fatal di alur cuti & tukar shift (dari error produksi asli)
1. **`markLeave()`** (dipanggil saat approve cuti/izin/sakit) melakukan
   mass-update yang coba jadikan `shift_key` SEMUA baris employee+tanggal
   itu = 0 sekaligus. Karyawan yang baru ambil-alih shift rekan (jadi
   punya 2 baris hari itu) lalu sakit → baris kedua nabrak baris pertama
   yang barusan jadi `shift_key=0`. **Solusi**: baris-baris hari itu
   diringkas jadi SATU baris libur (baris lain dihapus), update lewat
   instance model (bukan query builder) supaya event `saving()` milik
   `HasShiftKey` otomatis menjaga `shift_key` tetap konsisten dengan
   `shift_id`.
2. **`applySwap()`** menukar **kepemilikan** baris (`employee_id`), bukan
   isinya. Dua masalah: (a) pengambilalihan satu arah ke orang yang
   **sudah** dijadwalkan shift persis sama hari itu bikin dia dobel-pemilik
   shift identik — mustahil secara fisik; (b) tukar **mutual** antara dua
   orang yang `shift_key`-nya kebetulan sama (sama-sama Pagi, beda posisi)
   **selalu gagal** — sudah diverifikasi empiris, **SQLite tidak menunda
   pengecekan constraint unik sampai akhir statement**, bahkan dalam satu
   UPDATE yang menyentuh banyak baris sekaligus. **Solusi**: tukar mutual
   sekarang menukar **ISI** dua baris (shift & divisi), `employee_id` tiap
   baris tidak pernah disentuh — jadi tidak pernah ada keadaan antara yang
   bisa tabrakan. Pengambilalihan satu arah tetap lewat pemindahan
   kepemilikan (memang tidak ada baris kedua untuk ditukar isinya), dengan
   penjagaan baru (`assertTidakDobelShift`) yang melempar pesan jelas kalau
   akan bikin dobel.

### 4.8 Pengganti cuti/izin/sakit sekarang beneran masuk roster
**Masalah**: kolom "Pengganti" di form cuti cuma syarat administratif
(harus klik Bersedia dulu) — begitu disetujui, dia tidak pernah tersentuh
roster. Orang yang cuti jadi Libur, shiftnya kosong, yang bilang bersedia
bantu kehilangan shiftnya begitu saja.
**Solusi**: `applyLeave()` sekarang mengambil jadwal ASLI orang yang cuti
(sebelum `markLeave()` mengubahnya) lalu meng-assign pengganti ke shift &
divisi yang sama persis — termasuk kalau yang cuti dobel-shift hari itu,
penggantinya menutup semuanya. Konsekuensi: pengganti sekarang kena aturan
Alpha yang sama kalau ternyata tidak masuk.
**PENTING**: ini tidak retroaktif — kasus yang disetujui SEBELUM fix ini
jalan harus dibetulkan manual (sudah terjadi sekali, ada skrip perbaikannya
di riwayat chat, intinya panggil `RosterService::assign()` manual untuk
penggantinya).

### 4.9 Branding, logo, favicon
`APP_NAME` di `.env` server **harus** diisi manual (`.env` tidak ikut git).
Nilai bawaan di `config/app.php` dkk diganti dari `'Laravel'` ke
`'21 Kafe'` sebagai jaring pengaman. Logo asli (PNG resolusi tinggi,
padding transparan lebar) dipangkas & diperkecil ke `public/img/logo-21-{putih,hitam}.png`
(8 KB, dari 320 KB), dipakai lewat komponen `<x-logo-21 varian="...">`.
**Pelajaran penting**: logo yang dipotong mepet tepi kanvas akan selalu
tersenggol sudut membulat wadahnya berapa pun ukurannya diatur di CSS —
ruang napas (~32%) harus dibakar ke DALAM berkas gambarnya, bukan diatur
lewat padding CSS.

### 4.10 Shift bisa sembunyikan jamnya + jam pulang ditandai (+1 hari)
Kolom `shifts.show_hours` (boolean, default true) — dipakai sekarang buat
Shift Middle karena jam pastinya belum final dikonfirmasi ke pemilik kafe.
Data jam (11:30–01:00) tetap dipakai penuh buat hitung telat/jendela absen,
cuma tidak ditulis ke layar. Terpisah dari itu: jam pulang yang jatuh di
tanggal berikutnya dari `work_date` (shift Malam & Middle, lewat tengah
malam) sekarang ditandai `(+1 hari)` di semua tempat yang menampilkannya
(dulu cuma ada di tabel Scan Mentah & Excel).

## 5. Data & keputusan bisnis yang sudah diambil (bukan cuma kode)

- **Roster Agustus** (mulai 15 Agustus) & **September penuh** sudah diisi
  untuk Kitchen, Barista (2 tim), Waiters (rotasi 4 minggu), Logistik.
  **Kasir belum ada roster resmi** — sengaja dibiarkan pakai fallback
  (lihat 4.1) sampai jadwal kasir dikirim.
- **Divisi Logistik** baru dibuat (sebelumnya Alvano nyasar ke Barista).
- **Cleaning Service dinonaktifkan** (`is_active=false`, syarat tenaga
  dinolkan) — posisi ini memang belum ada orangnya, biar warning validator
  roster berhenti bising.
- **Shift Middle**: 11:30–01:00 (crosses midnight), berlaku Jumat/Sabtu/Minggu
  untuk waiters. **Jam pulangnya (01:00) masih perlu dikonfirmasi ke bos**
  — kalau ternyata beda, tinggal `Shift::where('code','middle')->update([...])`
  lalu `attendance:compute` ulang rentang yang kepengaruh.
- **Kebutuhan tenaga (`staffing_requirements`) shift Malam Waiters diturunkan
  dari 3 ke 2** — polanya memang selalu 2 orang malam + 1 middle, angka "3"
  bikin warning "kurang tenaga" muncul terus padahal sudah pas.
- Siklus rotasi 4-mingguan waiters: patokan "Minggu 1" = **Senin 27 Juli
  2026** (dikoreksi dari asumsi awal 3 Agustus setelah dicek ke lapangan).

## 6. Yang masih menggantung (per tanggal dokumen ini)

- [ ] Jam pulang Shift Middle (01:00) — konfirmasi final ke bos.
- [ ] Roster Kasir — belum dibuat sama sekali.
- [ ] Auto-deploy Hostinger masih nyasar ke folder `kafe/` — perlu dibenerin
      dari pengaturan Git di hPanel biar `git pull` manual tidak perlu terus.
- [ ] Kolom "andi" di jadwal kasir lama — orangnya belum terdaftar di sistem
      (nama lengkap & PIN mesin belum ada), sengaja di-skip.
- [ ] Payroll belum pernah digenerate sama sekali — begitu dijalankan
      pertama kali, cek dulu apakah ada slip lama yang perlu diabaikan.
- [ ] Halaman Roster manajer tidak punya penanda "baris ini muncul karena
      apa" (manual/swap/leave) — kalau ada shift nongol tiba-tiba (dari
      swap atau pengganti cuti), manajer bisa bingung asalnya dari mana.
      Belum dikerjakan, cuma dicatat sebagai potensi kebingungan.
- [ ] Kebijakan "SEMENTARA" di 4.1 harus ditinjau ulang & dicabut begitu
      roster dipakai penuh untuk SEMUA karyawan (termasuk kasir).

## 7. Jebakan teknis untuk diketahui (gotchas)

1. **`RosterAssignment.shift_key` HARUS selalu sama dengan `shift_id`**
   (`?? 0`), dijaga trait `App\Models\Concerns\HasShiftKey` lewat event
   `saving()`. Event ini **TIDAK jalan** kalau update lewat query builder
   (`Model::query()->update([...])`) — cuma jalan kalau lewat instance
   (`$model->update([...])`). Kalau nulis mass-update ke `roster_assignments`
   atau `attendances`, ingat ini.
2. **SQLite tidak menunda pengecekan UNIQUE constraint sampai akhir
   statement** — sudah diverifikasi langsung, bahkan satu UPDATE yang
   menyentuh banyak baris sekaligus (`CASE id WHEN...`) tetap gagal kalau
   ada keadaan antara yang tabrakan. Kalau perlu "menukar" dua baris yang
   sama-sama punya nilai UNIQUE, jangan pindahkan kepemilikan bolak-balik —
   tukar ISI-nya, atau pakai nilai sementara yang dijamin aman.
3. **`whereDate('kolom', $tanggal)` vs mencari pakai string `"Y-m-d"`** —
   kolom `work_date` di `roster_assignments` & `attendances` tersimpan
   sebagai `"Y-m-d 00:00:00"`. `updateOrCreate`/`where` yang mencari pakai
   string pendek `"Y-m-d"` (bukan objek Carbon) **tidak pernah ketemu**,
   dan `updateOrCreate` diam-diam berubah jadi INSERT yang nabrak unique
   constraint. Sudah kena 2 kali (di `AttendanceComputer` dan
   `RosterService`) sebelum polanya disadari.
4. **Migrasi FK `cascadeOnDelete()`** dari `shift_swap_requests` ke
   `roster_assignments` (`requester_assignment_id`, `partner_assignment_id`)
   — jangan pernah hapus lalu buat ulang baris `RosterAssignment` yang
   masih dirujuk pengajuan tukar shift, itu akan ikut menghapus riwayat
   pengajuannya lewat cascade.
5. **Tinker/PsyShell butuh `shell_exec`** buat cek update manual saat
   start — mati di Hostinger. Semua skrip database di produksi pakai
   `php /tmp/skrip.php` dengan bootstrap manual (lihat bagian 2), bukan
   `php artisan tinker --execute=...`.
6. **Test yang mengandalkan `karyawan()` factory default** (di
   `AttendanceComputerTest` dan turunannya) — employee dibuat dengan
   `default_shift_id` terisi tapi **tanpa** `RosterAssignment` asli. Ini
   sengaja meniru kondisi produksi (roster belum diisi penuh), bukan bug
   di test.
7. **Memanggil `RosterService::assign()` dua kali berturut-turut untuk
   employee+tanggal yang sama TIDAK membuat double-shift** — panggilan
   kedua akan MEMINDAHKAN shift (menghapus baris pertama), sesuai
   perbaikan di 4.5. Kalau butuh setup double-shift asli di test, pakai
   `RosterAssignment::create()` langsung untuk baris kedua.

---

*Kalau melanjutkan kerja dari dokumen ini: baca dulu bagian 2 (lingkungan)
dan bagian 7 (jebakan) sebelum menyentuh kode roster/absensi/lembur —
sebagian besar bug di atas berulang polanya (mass-update yang bentrok
constraint, pencarian tanggal pakai string, asumsi soal `proc_open`).*
