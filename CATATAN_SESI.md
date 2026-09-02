# Catatan Konteks Proyek — 21 Kafe

Dokumen ini dibuat supaya AI/developer lain yang lanjut kerja di proyek ini
tidak perlu menemukan ulang apa yang sudah ditemukan di sesi sebelumnya —
terutama jebakan-jebakan yang sudah pernah bikin bug produksi.

**Terakhir diperbarui: 21 Agustus 2026.** Cabang kerja `feat/hris-lengkap`,
sudah menyatu ke `main` (yang di-deploy).

Kalau ada yang di sini ternyata sudah berubah (kode sudah direfactor, bug
sudah dibenerin ulang, dst), percayai kode yang ada sekarang — dokumen ini
snapshot per tanggal di atas, bukan kebenaran abadi.

---

## 0. Cara melanjutkan di sesi baru

Buka sesi baru di folder proyek ini, lalu mulai dengan kira-kira:

> Baca `CATATAN_SESI.md` dulu untuk konteks proyek. Aku mau lanjut kerja
> di sistem absensi 21 Kafe.

Yang perlu diketahui AI/developer barunya sejak menit pertama:

1. **Server produksi diakses lewat SSH**, dan saya (Claude) tidak punya
   aksesnya — semua perintah server harus dijalankan sendiri oleh pemilik,
   lalu hasilnya ditempel balik ke chat. Jangan pernah berasumsi sebuah
   perintah sudah jalan tanpa melihat outputnya; ini sudah beberapa kali
   bikin perbaikan dikira selesai padahal belum.
2. **Baca bagian 2 (lingkungan) dan bagian 7 (jebakan) sebelum menyentuh
   kode roster/absensi/lembur.** Sebagian besar bug di proyek ini berulang
   polanya.
3. **Perubahan data produksi lewat perintah artisan**, bukan skrip PHP
   tempelan — lihat bagian 8. Skrip panjang lewat SSH sudah terbukti sering
   kelewat separuh tanpa ada yang sadar.

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

### 4.11 Rotasi waiters dari Antigravity — pemetaan "Nur" tertukar orang

Sesi terpisah memakai AI lain (Antigravity/Gemini) untuk menerapkan rotasi
4-mingguan waiters 17 Agt–30 Sep. Polanya benar seluruhnya (sudah dicocokkan
hari per hari), tapi **"Nur" dipetakan ke Nurdiansyah (PIN 8, divisi
Kitchen)** — seharusnya **Nuryati (PIN 19)**.

Yang bikin ini lolos: pencariannya memakai
`->where('pin_device', '8')->orWhere('name', 'Nurdiansyah')` — "salah satu
cocok" sudah diterima, jadi pemetaan yang salah pun tidak mengeluh. Dan
**tesnya ikut mengunci kesalahan yang sama** (membuat karyawan bernama
"Nurdiansyah" PIN 8 sebagai `nur`), sehingga lulus sambil salah — itu bagian
paling berbahayanya, karena kesalahannya jadi terlihat terverifikasi.

Diperbaiki: PIN dan nama harus cocok BERPASANGAN, dan perintahnya berhenti
dengan pesan yang menyebut siapa pemilik PIN itu sebenarnya. Ditambah tes
yang menjaga Nurdiansyah tidak kebagian jadwal waiters sama sekali.

Untungnya versi salahnya **tidak pernah dijalankan di produksi** — sempat
dicek sebelum diperbaiki.

### 4.12 Cuti yang disetujui bisa ketiban jadwal kerja

**Masalah**: `assign()` melindungi baris cuti dari terhapus, tapi tidak
menolak menjadwalkan orang yang sedang cuti — jadi baris kerja baru
**menempel di sampingnya**, dan di roster orangnya terlihat tetap masuk
padahal cutinya sudah disahkan.

Kejadian nyatanya: cuti Julian 22–23 Agustus disetujui 16 Agustus, lalu
skrip rotasi waiters dijalankan 18 Agustus untuk rentang 17 Agt–30 Sep dan
menempelkan Shift Malam di tanggal cutinya. Tanpa error, tanpa peringatan.

**Solusi**: `assign()` menolak kalau tujuannya shift kerja dan orang itu
punya cuti disetujui hari itu. Menandai libur (shift null) tetap boleh —
itu bukan menjadwalkan kerja. Jalur pengajuan resmi (`source` leave/swap)
dikecualikan, karena itu yang berhak mengubah baris cuti (misalnya pengganti
yang menutup shift orang yang cuti). `roster:apply-waiters` **melewati**
orang yang cuti lalu melaporkannya di akhir, bukan gagal total — kalau satu
orang cuti bikin seluruh rotasi batal, ujungnya orang akan mematikan
penjagaannya supaya skripnya jalan.

### 4.13 Dua pintasan admin: `attendance:waive-late` & `roster:set`

Memaafkan telat dan mengubah jadwal satu orang adalah pekerjaan admin yang
paling sering muncul, tapi sebelumnya cuma bisa lewat skrip PHP panjang yang
ditempel ke SSH. **Dampaknya nyata**: koreksi telat gagal diterapkan dua kali
berturut-turut karena skripnya kelewat separuh, dan tidak ada yang tahu
sampai orangnya membuka rekap sendiri.

Lihat bagian 8 untuk cara pakainya. Keduanya menampilkan kondisi **sebelum
dan sesudah**, supaya kalau hasilnya tidak seperti yang dikira, ketahuan saat
itu juga.

### 4.14 Jam shift khusus untuk satu tanggal

Bos sesekali minta jam berbeda di satu hari tertentu (mis. Jumat 21 Agustus:
Shift 1 jadi 08:00–16:00, Shift 2 jadi 13:30–22:30).

Jam di tabel `shifts` berlaku **global**, jadi mengubahnya untuk sehari ikut
mengubah semua tanggal — termasuk yang sudah lewat, karena cron menghitung
ulang dua hari terakhir tiap 15 menit. "Ubah lalu kembalikan" karena itu
bukan pilihan yang aman.

Alternatif "bikin shift baru khusus sehari" juga ditolak: syarat tenaga
terikat ke `shift_id`, jadi kuota hari itu tidak terhitung dan muncul
peringatan "kurang tenaga" palsu — plus karyawan melihat nama shift asing
di jadwalnya.

**Solusi**: kolom `start_time_override` / `end_time_override` di
`roster_assignments`, dibaca `WorkWindow::for()`. Nama shift, warna, dan
kuota tetap normal; yang berbeda cuma jamnya, di tanggal itu saja. Tampilan
(dashboard admin, beranda & kalender karyawan) menampilkan jam efektif dan
menandainya "jam khusus" — kalau yang tampil jam master, karyawan datang di
jam yang salah padahal jadwalnya sudah diubah.

### 4.15 Scan yang dibuang jendela kerja — dan diagnosis yang sempat berbohong

**Masalah asli**: Abdila Riansyah (PIN 10) scan datang 07:54:07, tapi yang masuk
rekap justru scan 13:00 yang tidak disengaja. Sebabnya: hari itu Shift Malam
dapat jam khusus mulai 13:30, jendela scan ikut bergeser jadi baru dibuka 09:30
(4 jam sebelum jam masuk), dan scan 07:54 jatuh di luar jendela lalu **dibuang
tanpa error, tanpa jejak, tanpa tanda apa pun di rekap**. Akar sebenarnya:
pertukaran shift dengan Muhammad Nasdana Faza (PIN 12) yang tidak pernah
dimasukkan ke sistem, jadi rosternya memang shift yang salah.

Ini kelas kegagalan terburuk di proyek ini — rekapnya tetap terlihat wajar, jadi
yang ketahuan cuma karyawan yang kebetulan memeriksa rekapnya sendiri lalu
protes. Dua perintah baru dibuat untuk itu (lihat bagian 8):

- `attendance:jelaskan <pin> <tanggal>` — seluruh rantai untuk satu orang:
  jadwal & jam yang benar-benar dipakai, jendela yang dihasilkannya, SEMUA scan
  hari itu termasuk yang dibuang berikut alasannya, scan mana yang jadi jam
  masuk/pulang, lalu hasil akhirnya di rekap.
- `attendance:periksa --from= --to=` — menyapu semua karyawan, mencari rekap
  yang jam masuknya bukan scan pertama orang itu.

Jejaknya dibangun lewat `AttendanceComputer::jejak()` yang memakai helper yang
sama persis dengan perhitungan asli — **bukan salinan logikanya**. Diagnosis yang
punya salinan sendiri akan menyimpang diam-diam dari kenyataan.

**Pelajaran yang lebih mahal dari bug aslinya**: versi pertama `attendance:periksa`
melaporkan **57 rekap bermasalah dalam seminggu, dan semuanya salah**. Yang dikira
"scan datang pagi yang terbuang" ternyata scan PULANG shift malam hari sebelumnya
(jatuh sekitar 01:00). Batas bawah rentangnya masih tengah malam sementara batas
atasnya sudah memakai ambang 06:00 — dua batas yang tidak konsisten di satu
fungsi yang sama. Laporan itu nyaris dipakai untuk membetulkan roster satu minggu
penuh yang sebenarnya sudah benar. Sekarang dikunci tes (lihat jebakan nomor 9).

### 4.16 Pendaftaran karyawan baru bisa sepenuhnya jarak jauh

Dulu satu bagian selalu mengharuskan orang berdiri di depan mesin: pendaftaran
biometrik. Sekarang tidak, karena mesinnya mendukung pendaftaran WAJAH lewat
API — cukup minta swafoto.

`employee:daftar-wajah <pin> <foto.jpg>` memakai endpoint `set_userinfo`.
Tiga hal yang perlu diketahui sebelum menyentuhnya:

1. **Template wajah itu base64 GANDA.** Foto JPEG di-base64, dibungkus JSON
   `{"face":"<base64-jpeg>"}`, lalu SELURUH string JSON itu di-base64 sekali
   lagi. Satu lapis terlewat = ditolak mesin. Ada di `FaceTemplate`, jangan
   disusun ulang di tempat lain. Berlaku untuk Seri VIDA/VIVO; template tidak
   bisa dipindah antar tipe mesin yang berbeda.
2. **Foto wajib JPEG dan maksimal 100 KB**, close-up. Diperiksa dari byte
   pembuka berkasnya, bukan dari akhiran namanya — PNG yang diganti nama jadi
   `.jpg` akan terkirim mulus lalu gagal di mesin tanpa keterangan.
3. **`set_userinfo` ASINKRON.** Respons `success: true` cuma berarti perintahnya
   DITERIMA. Hasil sebenarnya datang belakangan ke webhook mesin dengan
   `data.status` (1 sukses, 2 gagal), dicocokkan lewat `trans_id`. Perintahnya
   menunggu callback itu; **tidak ada jawaban sengaja keluar dengan kode gagal**,
   supaya hasil yang tidak diketahui tidak pernah terbaca beres.

Yang masih harus dikerjakan lewat panel admin: **divisi**. `employee:add` dan
`employee:edit` tidak punya opsi divisi (relasinya `employee_divisions` dengan
penanda divisi utama), jadi setelah mendaftar, buka Karyawan → Detail dan set
divisinya. Kalau dilewat, orangnya tidak terhitung mengisi kuota tenaga di
validator roster.

### 4.17 Tukar libur: dua orang bertukar hari libur

Ditumpangkan ke `shift_swap_requests` yang sudah ada (kolom `kind`, plus
pasangan baris kedua), **bukan tabel baru** — karena tukar libur secara mekanis
adalah DUA kali tukar shift: isi baris kedua orang ditukar di tanggal libur
pengaju, lalu ditukar lagi di tanggal libur rekannya. Mesin penukarnya sudah
menyelesaikan bagian tersulitnya (menukar ISI baris, bukan kepemilikannya,
karena SQLite tidak menunda pengecekan constraint unik — lihat jebakan 2), dan
tabel kedua berarti menyalin pelajaran itu ke tempat yang akan menyimpang.

Alurnya lewat Pengajuan seperti tukar shift: pengaju memilih **hari liburnya
sendiri** dan **hari libur rekan yang dia inginkan** — rekannya ikut dari
pilihan kedua, tidak dipilih terpisah, supaya tidak mungkin terkirim kombinasi
orang dan tanggal yang tidak nyambung. Lalu rekan menyatakan bersedia, baru
manajer mengesahkan.

Ditolak kalau: rekan tidak terjadwal kerja di tanggal libur pengaju (atau
sebaliknya) — tukarnya jadi tidak impas, satu orang kehilangan libur tanpa ada
yang menggantikan; yang dipilih ternyata hari kerja atau **cuti** (cuti sudah
disahkan, tidak boleh dipindah lewat jalur ini); tanggalnya sudah lewat; atau
kedua liburnya jatuh di tanggal yang sama.

**Dua jebakan yang ketahuan saat mengerjakannya, keduanya sudah dikunci tes:**

1. **Status harus ikut ditukar, bukan cuma `shift_id`.** Baris libur yang
   menerima shift tetap berstatus `Off` kalau statusnya tidak disesuaikan, dan
   `AttendanceComputer` membaca STATUS — bukan `shift_id` — untuk memutuskan
   hari itu hari kerja atau bukan. Akibatnya orang yang benar-benar masuk tetap
   tercatat Libur, tanpa error apa pun.
2. **Semua nilai harus dibaca SEBELUM update pertama.** `$model->update()`
   mengubah modelnya di tempat, jadi membaca `$mine->shift_id` setelah `$mine`
   di-update mengembalikan nilai BARU — dan baris kedua ikut menerima shift
   yang sama, bukan shift lawannya. Gejalanya cuma "satu orang tidak jadi
   libur", tanpa error.

### 4.18 Libur pilihan sendiri untuk Logistik

Logistik masuk hampir tiap hari dan jatah liburnya **2 hari per bulan
kalender**, tanggalnya **dipilih sendiri** oleh orangnya lewat halaman
`karyawan/libur`. Aturannya di `App\Services\Roster\LiburPilihanService`,
angkanya di `config/attendance.php` bagian `libur_pilihan`.

Keputusan yang membentuknya:

- **Berlaku LANGSUNG tanpa persetujuan manajer.** Karena itu jatahnya
  ditegakkan di service, bukan di tampilan — menu yang disembunyikan tidak
  menghentikan siapa pun yang mengirim form-nya langsung, dan yang
  dipertaruhkan di sini jadwal kafe. Ada tesnya yang mengirim POST langsung.
- **Ada satu langkah konfirmasi.** Pilihannya tidak bisa dibatalkan sendiri,
  jadi satu salah klik berarti satu hari libur hilang. Layar konfirmasi
  menyebut tanggalnya dan sisa jatah setelahnya.
- **Jatah dijaga ketat** — pilihan ketiga ditolak, bukan cuma diperingatkan.
- **Libur yang dipasang admin lewat `roster:set` TIDAK memotong jatah.** Yang
  dihitung cuma baris dengan `source = 'pilihan'`.
- Berlaku untuk **divisi Logistik saja**, lewat daftar kode divisi di config
  supaya menambah divisi lain nanti tidak perlu menyentuh kode.

Jatah dihitung per bulan **tanggal yang dipilih**, bukan bulan berjalan —
daftar kandidat mencakup 60 hari ke depan jadi pilihannya bisa jatuh di bulan
berikutnya, dan layar konfirmasi menyebut angka bulan itu.

### 4.19 Membuat akun login karyawan

Sebelum ini **tidak ada jalur mana pun yang bisa MEMBUAT akun karyawan**. Panel
admin bisa mengganti nama panggilan dan mengatur ulang sandi, tapi keduanya
berhenti dengan "belum punya akun login"; `user:add` berbasis email dan tidak
menautkan akunnya ke karyawan. Jadi karyawan baru tidak pernah bisa masuk
sampai ada yang menyentuh database langsung.

`employee:akun <pin> --username=umin` membuat akunnya, menautkan ke karyawan,
memberi sandi acak, dan menandainya **wajib ganti sandi saat login pertama** —
sandi buatan admin belum jadi rahasia milik orangnya sampai dia menggantinya.
Sandinya ditampilkan SEKALI; yang tersimpan cuma hash-nya.

Hal-hal yang mengikat di tabel `users` dan gampang bikin bingung:
`email` **wajib terisi dan unik** padahal login memakai nama panggilan dan tidak
ada satu pun email yang dikirim (pemberitahuan lewat WhatsApp). Mengikuti pola
akun yang sudah ada: `nama@kafe.test` — `.test` TLD cadangan yang dijamin tidak
pernah resolve, jadi tidak mungkin ada surat nyasar ke alamat orang lain. Nama
panggilan dibatasi huruf kecil dan angka saja karena spasi dan huruf besar gagal
diketik di layar ponsel.

### 4.20 Pengajuan yang disetujui tidak pernah masuk ke rekap

**Masalah**: cron `attendance:compute` hanya menghitung ulang **dua hari
terakhir**, dan `approve()` tidak pernah memicu hitung ulang sama sekali. Jadi
persetujuan untuk tanggal yang lebih lama dari dua hari mengubah roster dan
menulis koreksi, tapi rekapnya tetap memperlihatkan angka lama: di halaman
Roster perubahannya terlihat, di Rekap Absensi tidak. Tanpa error, tanpa tanda
apa pun.

Ironisnya semua jalur ADMIN sudah menghitung ulang (`roster:set --recompute`,
`attendance:tandai`, `attendance:waive-late`, `roster:jam-khusus --recompute`) —
justru jalur yang dipakai KARYAWAN yang tidak. Itu sebabnya lama tidak
ketahuan: yang mengurus data sehari-hari selalu lewat perintah admin.

**Solusi**: `approve()` mengumpulkan tanggal terdampak per jenis pengajuan
(cuti: seluruh rentang; lembur & koreksi: satu tanggal; tukar: tanggal baris
rosternya, satu untuk tukar shift dan dua untuk tukar libur) lalu menghitung
ulang **setelah transaksi commit** — supaya perhitungannya membaca roster final,
bukan yang masih menggantung. Kegagalan hitung ulang sengaja TIDAK menjatuhkan
persetujuan: keputusannya sudah sah dan rekap adalah tabel turunan, sementara
melempar exception di situ akan membuat manajer mengira persetujuannya gagal
lalu menekan tombolnya lagi.

### 4.21 Satu scan diklaim dua shift sekaligus

**Masalah**: seseorang terjadwal Pagi (08:00–18:00) DAN Malam (14:00–01:00) di
hari yang sama, lalu menempel jari **sekali** jam 14:06. Jendela kedua shift itu
tumpang tindih, dan tiap baris mencari scannya sendiri-sendiri lewat
`logsIn()` — jadi scan yang sama jadi jam masuk untuk KEDUANYA: telat 7 menit di
shift yang benar, dan **telat 6 jam 7 menit** di shift pagi yang tidak pernah dia
jalani. Tidak ada error; yang ada cuma potongan gaji atas hari yang tidak pernah
terjadi.

**Solusi**: `konteksHarian()` membagikan scan secara EKSKLUSIF — tiap scan cuma
milik satu baris jadwal — dan `computeAssignment()` tidak lagi mencari scannya
sendiri. Diagnosis (`jejak()`) memakai konteks yang sama, jadi tidak mungkin
menyimpang dari perhitungan asli.

**Kenapa perbaikan yang paling gampang justru salah**: aturan "berikan scan ke
shift yang jam mulainya paling dekat" membereskan kasus di atas tapi merusak
dobel shift yang ASLI — scan PULANG shift pertama akan lari ke shift kedua yang
jam mulainya kebetulan lebih dekat, dan orangnya terbaca telat berjam-jam.
Karena itu kepemilikan diukur ke **seluruh rentang jadwal** (jam masuk sampai
jam pulang): nol kalau scan ada di dalamnya, selain itu jarak ke ujung terdekat.
Seri dipecahkan oleh jam masuk terdekat. Keduanya dikunci di
`SatuScanSatuShiftTest`.

Baris yang kalah tidak dapat scan sama sekali lalu jatuh ke **Alpha** — dan itu
memang yang diinginkan: salah jadwalnya jadi terlihat, bukan tersamar sebagai
telat berjam-jam.

**Catatan untuk tes**: `MasterDataSeeder` masih membawa jam shift LAMA (Pagi
09:00–17:00, Malam 17:00–01:00), sedangkan produksi sudah Pagi 08:00–18:00 dan
Malam 14:00–01:00. Tes yang bergantung pada tumpang tindih jam harus menyetelnya
sendiri, jangan mengandalkan seeder.

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
  untuk waiters. Jam-nya **sengaja disembunyikan dari tampilan**
  (`shifts.show_hours = false`) karena belum final dikonfirmasi ke bos —
  datanya tetap dipakai penuh untuk hitung telat & jendela absen, cuma tidak
  ditulis ke layar. Kalau nanti sudah pasti, `show_hours` tinggal
  dikembalikan ke `true`.
- **Nama karyawan pernah diubah**: "Dea Sofiyanti" → **"Dea Shofita Nur
  Utami"** (PIN 20, Kasir). Sekarang ada TIGA orang dengan "Nur" di namanya —
  Nuryati (waiters, 19), Nurdiansyah (kitchen, 8), dan Dea (kasir, 20).
  Jangan pernah mencari karyawan dari kemiripan nama; pakai PIN.
- **Kebutuhan tenaga (`staffing_requirements`) shift Malam Waiters diturunkan
  dari 3 ke 2** — polanya memang selalu 2 orang malam + 1 middle, angka "3"
  bikin warning "kurang tenaga" muncul terus padahal sudah pas.
- **Siklus rotasi 4-mingguan waiters**: patokan "Minggu 1" = **Senin 17 Agustus
  2026** (jadwal BARU per 18 Agustus 2026 — menggantikan jadwal lama yang
  patokannya 27 Juli; jangan pakai patokan lama lagi). Berlaku 17 Agt–30 Sep,
  siklusnya berulang tiap 28 hari. Tanggal 15–16 Agustus tetap apa adanya.
  Diterapkan lewat `php artisan roster:apply-waiters --recompute`.

  **Pemetaan nama panggilan → karyawan** (sumber kekeliruan yang sudah pernah
  terjadi, jangan ditebak dari kemiripan nama):
  - Waye = **Farrel Daffa** (PIN 3)
  - Dafa = **Dava Erik Prasetiyo** (PIN 2)
  - Nur  = **Nuryati** (PIN 19)
  - Amal = **Muhammad Julian Ikhlusul Amal** (PIN 6)

  **Nur BUKAN Nurdiansyah.** Nurdiansyah panggilannya "Dian", PIN 8, divisi
  **Kitchen** — pernah tertukar dan bikin orang Kitchen masuk rotasi waiters
  17 Agt–30 Sep sementara Nuryati hilang dari jadwal sama sekali. Perintahnya
  sekarang mencocokkan PIN **dan** nama berpasangan, dan berhenti dengan
  pesan jelas kalau tidak cocok.

  Dua hal di pola ini terlihat seperti salah ketik tapi memang begitu
  jadwalnya — sudah dikunci di `WaiterRosterRotationTest`, jangan
  "diperbaiki":
  - **Hari Minggu tidak punya shift middle** — dua orang di shift 1, dua di
    shift 2 (beda dari Jumat/Sabtu yang selalu ada middle).
  - **Kamis Minggu 1 (20 Agt) polanya sendiri**: "1 = Waye". Kamis minggu
    2–4 barulah "1 = Amal". Senin–Rabu memang sama tiap minggu, jadi Kamis
    yang beda ini gampang disangka keliru.

## 6. Yang masih menggantung (per 21 Agustus 2026)

Antrean perintah 20-21 Agustus **sudah dijalankan semua** (roster Dava, Dea,
Sinta; jam khusus pagi & malam 21 Agustus; pemaafan telat Fikri), ditambah
perbaikan tukar shift 21 Agustus yang tidak pernah masuk sistem: Abdila (10)
ke Shift Pagi, Nasdana (12) ke Shift Malam, Dava (2) dari Middle ke Malam.
Sudah diverifikasi lewat `attendance:periksa` — bukan diasumsikan.

Catatan urutan yang terbukti sekali lagi di sini: `roster:set 2` yang pertama
**tidak jadi** dan tidak ada yang sadar sampai rekapnya dilihat. Sebelum
menganggap sebuah perintah selesai, **lihat outputnya**.

**Keputusan/pekerjaan yang belum tuntas:**

- [ ] Scan yang dibuang jendela kerja masih tidak kelihatan di rekap maupun
      di halaman admin — cuma ketahuan kalau `attendance:jelaskan` /
      `attendance:periksa` dijalankan. Idealnya baris rekap yang punya scan
      terbuang diberi tanda sendiri, supaya kegagalannya berteriak, bukan
      menunggu ditanya.

- [ ] Payroll belum pernah digenerate sama sekali. Begitu dijalankan pertama
      kali, cek dulu apakah ada slip lama yang perlu diabaikan.
- [ ] Auto-deploy Hostinger masih nyasar ke folder `kafe/` — perlu dibenerin
      dari pengaturan Git di hPanel biar `git pull` manual tidak perlu terus.
- [ ] Roster **Kasir September** belum ada (Agustus sudah). Selama kosong,
      Dea & Sinta jatuh ke jalur cadangan di 4.1 (tidak scan = Libur).
- [ ] Kolom "andi" di jadwal kasir lama — orangnya belum terdaftar di sistem
      (nama lengkap & PIN mesin belum diberikan), sengaja di-skip.
- [ ] Halaman Roster manajer tidak punya penanda "baris ini muncul karena
      apa" (manual/swap/leave). Kalau ada shift nongol tiba-tiba (dari swap
      atau pengganti cuti), manajer bisa bingung asalnya dari mana.
- [ ] Kebijakan "SEMENTARA" di 4.1 harus ditinjau ulang & dicabut begitu
      roster dipakai penuh untuk SEMUA karyawan (termasuk kasir).
- [ ] Jam khusus (`roster:jam-khusus`) menempel per-orang, bukan per
      tanggal+shift — lihat gotcha nomor 8. Berfungsi, tapi rapuh terhadap
      urutan. Pindahkan ke tabel `shift_time_overrides` kalau sudah sering
      dipakai.
- [ ] Peringatan validator "kurang tenaga" masih bising untuk shift Middle —
      Middle tidak punya `staffing_requirements`, jadi orang yang di Middle
      tidak terhitung mengisi kuota mana pun.

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
3. **Jebakan tanggal — yang paling sering menggigit di proyek ini.**
   Kolom `work_date` di `roster_assignments` & `attendances` tersimpan
   sebagai `"Y-m-d 00:00:00"`, bukan `"Y-m-d"`. Akibatnya:
   - `updateOrCreate`/`where` yang mencari pakai string pendek `"Y-m-d"`
     **tidak pernah ketemu** — dan `updateOrCreate` diam-diam berubah jadi
     INSERT yang nabrak unique constraint.
   - **`whereBetween('work_date', ['2026-08-22', '2026-08-23'])` MEMBUANG
     tanggal 23**, karena `"2026-08-23 00:00:00" > "2026-08-23"` secara
     perbandingan string. Ini bikin diagnosis salah ("datanya tidak ada"
     padahal ada).
   - `whereIn('work_date', ['2026-08-17', '2026-08-21'])` juga tidak pernah
     cocok, dengan alasan yang sama.

   **Selalu pakai `whereDate()`, objek Carbon, atau batas atas eksplisit**
   (`'2026-08-23 23:59:59'`). Pola ini sudah menggigit **tiga kali** — dua
   kali di kode produksi (`AttendanceComputer`, `RosterService`) dan
   sekali di skrip diagnosis, yang sempat bikin kesimpulan keliru.
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
8. **`roster:jam-khusus` menempel ke ORANG, bukan ke tanggal+shift.**
   Kolom `start_time_override`/`end_time_override` ada di
   `roster_assignments`, jadi jam khusus cuma menempel pada orang yang
   **saat itu** terjadwal di shift tersebut. Kalau roster tanggal itu
   diubah SETELAH jam khusus dipasang, orang baru yang masuk ke shift itu
   **tidak ikut kebagian** — dia pakai jam master, dan telatnya salah
   hitung tanpa ada peringatan apa pun.

   **Aturannya: pasang jam khusus PALING AKHIR**, setelah roster tanggal
   itu benar-benar final. Perintahnya aman diulang, jadi kalau roster
   terlanjur berubah, tinggal jalankan lagi.

   Perbaikan yang lebih benar (belum dikerjakan): pindahkan override ke
   tabel sendiri berkunci (work_date, shift_id), supaya berlaku untuk
   siapa pun yang terjadwal di situ tanpa peduli urutan.

9. **"Hari" untuk scan = HARI OPERASIONAL (06:00–06:00), bukan tanggal
   kalender.** Shift malam berakhir 01:00, jadi scan pulangnya jatuh di
   tanggal berikutnya tapi tetap milik hari kemarin. Kalau mengambil rentang
   scan satu hari, **kedua batasnya harus ikut ambang yang sama**
   (`ATTENDANCE_DASHBOARD_CUTOVER_HOUR`) — memakai tengah malam sebagai batas
   bawah sementara batas atasnya 06:00 membuat scan pulang shift malam kemarin
   terhitung sebagai "scan pertama hari ini". Sudah menggigit sekali, dan
   akibatnya bukan error melainkan **laporan yang salah tapi meyakinkan**
   (57 temuan palsu, lihat 4.15). Pakai `AttendanceComputer::scanHarian()`,
   jangan menulis rentangnya sendiri.

10. **`attendance:periksa` tidak menangkap roster yang salah kalau tidak ada
   scan yang terbuang.** Orang yang terjadwal di shift yang salah tapi scan
   pertamanya kebetulan masih masuk jendela shift itu tidak akan muncul — dia
   cuma terlihat sebagai telat besar yang tidak masuk akal. Contoh nyatanya
   Dava 21 Agustus: terjadwal Middle (11:30), datang 12:53, terbaca telat
   1j 24m, padahal shift yang dia jalani mulai 13:30. Telat yang janggal tetap
   harus dicurigai sebagai roster salah, bukan cuma dimaafkan.

11. **`trans_id` ke Fingerspot HARUS muat di integer 32-bit bertanda
   (maks 2147483647).** Ditemukan dari kejadian nyata, tidak ada di
   dokumentasi: nilai 1787319375917 (timestamp milidetik) dipantulkan mesin
   balik sebagai **2147483647** — dipangkas ke batas atas int32. Akibatnya
   callback-nya tidak pernah cocok dengan yang ditunggu, dan pendaftaran
   wajah yang **sebenarnya BERHASIL** terlaporkan sebagai "mesin tidak
   menjawab". Semua nilai kegedean dipangkas ke angka yang SAMA, jadi dua
   perintah berbeda pun jadi tidak bisa dibedakan lagi. Sekarang `transId()`
   memakai `random_int(1, 2147483647)` — acak, bukan timestamp detik, supaya
   dua perintah di detik yang sama tidak bertabrakan. Contoh di dokumentasi
   resmi memakai `"trans_id": "1"`, dan itu petunjuk yang baru masuk akal
   setelah tahu batasnya.

## 8. Perintah yang sering dipakai (jalankan di server, folder live)

Semua ini artisan command — **jangan lagi pakai skrip PHP tempelan lewat
SSH**, itu sudah terbukti sering kelewat separuh tanpa ada yang sadar.

```bash
# Tandai status satu hari: sakit, izin, cuti, hadir, libur, alpha (alasan WAJIB)
php artisan attendance:tandai <pin> <tanggal> sakit --alasan="Ada surat dokter"
php artisan attendance:tandai <pin> <tanggal> sakit --batal    # kembalikan

# Koreksi jam masuk/pulang buat yang LUPA absen (alasan WAJIB)
php artisan attendance:jam <pin> <tanggal> --masuk=08:00 --pulang=18:00 --alasan="Lupa tempel jari"
php artisan attendance:jam <pin> <tanggal> --batal

# Maafkan telat (alasan WAJIB, tanpa itu ditolak)
php artisan attendance:waive-late <pin> [tanggal] --alasan="Motor mogok"
php artisan attendance:waive-late <pin> [tanggal] --batal     # kembalikan

# Hapus SATU baris roster (buat baris dobel yang keliru). roster:set tidak
# bisa: dia melindungi baris ber-source swap/leave.
php artisan roster:hapus <pin> <tanggal> <kode-shift>
php artisan roster:hapus <pin> 2026-09-01..2026-09-30      # serentang, semua shift

# Ubah jadwal satu orang, boleh beberapa tanggal sekaligus
php artisan roster:set <pin> 2026-08-21=malam 2026-08-22=pagi 2026-08-23=libur \
    --divisi=kasir --recompute

# Jam shift khusus untuk SATU tanggal (tidak menyentuh master shift)
php artisan roster:jam-khusus 2026-08-21 pagi 08:00 16:00 --recompute
php artisan roster:jam-khusus 2026-08-21 pagi 08:00 16:00 --hapus --recompute

# Lihat roster sebulan sebagai kalender, satu baris per orang
php artisan roster:lihat 2026-08
php artisan roster:lihat 2026-08 --divisi=kasir
php artisan roster:lihat 2026-08 --pin=20

# Periksa kewajaran roster sebulan: bentrok shift, kurang tenaga, dan siapa
# yang belum dijadwalkan sama sekali
php artisan roster:periksa 2026-09

# Rotasi 4-mingguan waiters (17 Agt = Minggu 1)
php artisan roster:apply-waiters --recompute

# Hitung ulang rekap absensi
php artisan attendance:compute --from=2026-08-18 --to=2026-08-20

# Kenapa rekap seseorang berbunyi begitu — SELURUH scan hari itu, termasuk
# yang dibuang jendela kerja, berikut alasannya
php artisan attendance:jelaskan <pin> [tanggal]

# Sapu semua orang: cari rekap yang jam masuknya bukan scan pertamanya
php artisan attendance:periksa --from=2026-08-15 --to=2026-08-21

# Siapa saja yang berstatus tertentu, dan kapan. Angka ringkasan di web
# tidak menyebutkan siapa — ini yang mencarinya.
php artisan attendance:daftar --status=alpha --from=2026-08-01 --to=2026-08-31

# Telat yang JANGGAL hampir selalu berarti jadwalnya yang salah, bukan orangnya
# yang telat. --status=semua supaya yang berstatus hadir ikut terjaring.
php artisan attendance:daftar --status=semua --telat-min=120 --from=2026-08-01 --to=2026-08-31

# Lihat aliran data absensi hari ini, dari callback sampai rekap
php artisan attendance:status

# Karyawan
php artisan employee:akun <pin> --username=umin   # akun login + sandi acak
php artisan employee:add --pin=21 --name="Nama" --shift="Shift Pagi" --joined=2026-08-22
php artisan employee:daftar-wajah 21 /path/foto.jpg     # JPEG, maks 100 KB

# Jawaban asinkron dari mesin (set_userinfo dll). Tanpa argumen = 10 terakhir,
# sekalian memastikan webhook-nya hidup.
php artisan fingerspot:callback <trans_id>
php artisan fingerspot:callback
php artisan employee:list
php artisan employee:edit <pin> --name="Nama Baru"
```

**PIN yang sering dipakai**: Farrel Daffa 3 · Dava Erik 2 · Nuryati 19 ·
Julian Amal 6 · Fikri Imamy 11 · Nurdiansyah 8 · Dea 20 · Sinta 16.

---

*Kalau melanjutkan kerja dari dokumen ini: baca dulu bagian 2 (lingkungan)
dan bagian 7 (jebakan) sebelum menyentuh kode roster/absensi/lembur —
sebagian besar bug di atas berulang polanya (mass-update yang bentrok
constraint, pencarian tanggal pakai string, asumsi soal `proc_open`).*
