# Tahap 1 — Analisis Kebutuhan Bisnis

Cafe Workforce Management System
Tanggal: 6 Agustus 2026
Status: menunggu konfirmasi 4 keputusan bisnis (lihat bagian H)

---

## 0. Baseline — apa yang SUDAH ada di project ini

Sebelum merancang apa pun, saya audit dulu kode yang sudah berjalan. Ini penting
karena beberapa asumsi di brief ternyata sudah terjawab, dan beberapa lainnya
justru bertabrakan dengan yang sudah dibangun.

**Stack yang sudah terpasang**

| Hal | Kondisi |
|---|---|
| Framework | Laravel 13.8, PHP 8.3 |
| Frontend | Blade + Vite (belum ada SPA) |
| Auth | Session-based, `users` dengan role `owner` / `manager` |
| Excel | phpoffice/phpspreadsheet (dipakai export laporan bulanan) |
| Queue | Ada konfigurasi, scheduler aktif (`schedule:run`) |
| Test | 11 file feature test, sudah menutup jalur Fingerspot |

**Pipeline absensi yang sudah jalan**

```
Mesin Fingerspot
   │
   ├── (A) Webhook push realtime ──> device_callbacks (arsip mentah, append-only)
   │                                        │
   │                                        │ cron tiap 1 menit
   │                                        ▼
   └── (B) Cron get_attlog 02:00 ────> attendance_logs (1 baris = 1 scan)
                                            │
                                            │ cron tiap 15 menit
                                            ▼
                                       attendances (1 baris = 1 karyawan/hari)
```

Yang sudah benar dan **harus dipertahankan**:

1. **Dua jalur masuk data yang saling menambal.** Webhook untuk realtime, cron
   `get_attlog` untuk menambal scan yang webhook-nya hilang. Ini lebih tangguh
   daripada "import manual" yang diminta di brief.
2. **Anti-duplikat lewat `scan_minute`.** Webhook mengirim presisi menit,
   `get_attlog` mengirim presisi detik. Unique key dipasang di menit yang sudah
   dipangkas, jadi scan yang sama dari dua jalur bentrok dan yang kedua ditolak.
   Ini keputusan yang tepat dan tidak boleh diubah.
3. **`device_callbacks` append-only.** Arsip mentah tidak pernah dihapus proses
   olahan. Kalau ada sengketa, data bisa diputar ulang dari nol.
4. **`WorkWindow`.** Sudah menangani shift malam yang melewati tengah malam —
   jendela shift malam tanggal 6 = 6 Agustus 13:00 s/d 7 Agustus 05:00. Ini
   jantung sistem absensi kafe dan sudah benar sejak awal.
5. **PIN mesin sebagai identitas** (`employees.pin_device`, string, unique).
   Sesuai permintaan brief: nama bukan acuan.
6. **Snapshot `shift_id` + `scheduled_in` ke baris `attendances`.** Rekap bulan
   lalu tidak berubah walau karyawan pindah shift.

**Yang belum ada sama sekali**: divisi, roster, modul pengajuan, lembur, payroll,
slip gaji, login karyawan, audit log, notifikasi, soft delete, kuota cuti.

Artinya: yang dibangun ini **bukan project baru**, tapi perluasan dari modul
absensi yang sudah matang menjadi HRIS penuh. Pendekatan saya: modul absensi
eksisting dipertahankan sebagai *bounded context* Attendance, dan modul baru
dibangun di sekitarnya tanpa merusak pipeline yang sudah teruji.

---

## A. Aktor dan kepemilikan proses

| Aktor | Peran dalam sistem | Catatan |
|---|---|---|
| Owner | Pemilik kafe | Sudah ada di kode (`EnsureUserIsOwner`). Brief menyebut hanya 2 role — perlu diselaraskan (lihat K-06) |
| Manager | Operator harian sistem | Membuat roster, approve pengajuan, generate payroll |
| Karyawan | 18 orang, semua full time | Belum punya akun login sama sekali saat ini |
| Mesin Fingerspot | Aktor non-manusia | Sumber data absensi, push otomatis |
| Scheduler (cron) | Aktor non-manusia | Parse callback, compute absensi, sync cadangan, tutup hari |

Catatan penting: **scheduler adalah aktor**, bukan sekadar teknis. Beberapa
status (Alpha) tidak pernah dibuat manusia — status itu lahir dari proses
penutupan hari otomatis. Ini harus eksplisit di ERD, bukan implisit di kode.

---

## B. Bahasa domain (ubiquitous language)

Supaya tidak ada ambiguitas di tahap-tahap berikutnya, istilah dikunci sekarang:

| Istilah | Definisi presisi |
|---|---|
| **Scan** | Satu tempelan jari di mesin. Baris di `attendance_logs`. Tidak punya makna masuk/pulang sampai diolah |
| **Work Date** | Tanggal kerja *logis*, bukan tanggal kalender scan. Scan pulang shift malam jam 01:00 tanggal 7 tetap milik work date 6 |
| **Work Window** | Rentang waktu yang dianggap milik satu work date untuk satu shift (jam shift ± 4 jam) |
| **Roster Assignment** | Satu penugasan: karyawan X, tanggal Y, shift Z, divisi D. Inilah "jadwal" |
| **Raw Attendance** | `attendance_logs`. Mentah, apa adanya dari mesin, tidak pernah diedit manusia |
| **Final Attendance** | Hasil olahan yang sudah divalidasi + koreksi yang disetujui. **Satu-satunya** yang boleh dibaca payroll |
| **Payroll Period** | Rentang tanggal penggajian (bukan bulan kalender). Punya status sendiri |
| **Rule** | Aturan yang bisa diubah manager (potongan telat, tarif lembur, BPJS). Punya masa berlaku |
| **Snapshot** | Salinan nilai rule saat payroll dihitung. Mengubah rule tidak boleh mengubah slip gaji yang sudah terbit |

---

## C. Aturan bisnis eksplisit (dari brief)

Diberi ID supaya bisa dirujuk di ERD, API, dan test case nanti.

**Absensi**

- **BR-01** Identitas absensi adalah Employee ID di mesin. Nama tidak pernah jadi acuan.
- **BR-02** Payroll dilarang membaca raw attendance. Hanya Final Attendance.
- **BR-03** Sistem tidak boleh baca mesin realtime. Semua proses baca tabel hasil sinkronisasi.
- **BR-04** Toleransi telat = 0. Masuk 09:01 untuk shift 09:00 = telat 1 menit.
- **BR-05** Potongan telat tidak per menit, tapi per tingkatan (1–10, 11–30, 31–60, >60). Tingkatan dan nominal configurable oleh manager.
- **BR-06** Pulang cepat dihitung, rule configurable.
- **BR-07** Istirahat ±1 jam, tidak perlu scan.

**Roster**

- **BR-08** Roster bulanan, bukan rolling mingguan.
- **BR-09** Roster bulan depan dibuat sekitar tanggal 20–25.
- **BR-10** Roster bisa diedit kapan saja selama payroll periode itu belum dikunci.
- **BR-11** Kebutuhan minimum shift pagi: Chef 2, Barista 2, Kasir 1, Waiter 1, CS sesuai kebutuhan.
- **BR-12** Kebutuhan minimum shift malam: Chef 3, Barista 2, Kasir 1, Waiter 3, CS sesuai kebutuhan.

**Lembur**

- **BR-13** Lembur tidak pernah otomatis. Wajib approval sebelum dikerjakan.
- **BR-14** Tanpa approval, waktu setelah jam kerja bukan lembur (dan tidak dibayar).
- **BR-15** Minimal lembur configurable, default 1 jam.
- **BR-16** Tarif lembur configurable.

**Pengajuan**

- **BR-17** Satu modul untuk semua pengajuan: cuti, izin, sakit, lembur, tukar shift, koreksi absensi.
- **BR-18** Koreksi absensi wajib alasan, bukti opsional, approval manager, dan tercatat di audit log.
- **BR-19** Tukar shift butuh persetujuan rekan **dan** manager. Setelah approve, roster berubah otomatis.
- **BR-20** Cuti yang disetujui mengubah roster otomatis.

**Payroll**

- **BR-21** Penggajian tanggal 21.
- **BR-22** THP = Gaji Pokok + Bonus + Lembur − BPJS − Potongan.
- **BR-23** Bonus manual, wajib beralasan.
- **BR-24** Jenis potongan dibuat manager (telat, alpha, kasbon, denda, lainnya), semuanya configurable.
- **BR-25** BPJS configurable.
- **BR-26** Setelah payroll selesai, periode itu LOCKED. Perubahan hanya lewat Reopen Payroll.
- **BR-27** Karyawan bisa lihat + unduh PDF slip gaji.

**Sistem**

- **BR-28** Hanya 2 role: Manager dan Karyawan.
- **BR-29** Semua aktivitas penting masuk audit log.
- **BR-30** Struktur DB harus siap notifikasi (WhatsApp menyusul), implementasi belum perlu.

---

## D. Temuan kritis: kapasitas SDM tidak cukup

Ini temuan HRIS, bukan temuan teknis, tapi berdampak langsung ke desain modul
roster — jadi harus diputuskan sebelum ERD dikunci.

**Kebutuhan tenaga per hari** (di luar Cleaning Service):

| Divisi | Pagi | Malam | Per hari | Per minggu (×7) | Headcount minimum jika libur 1 hari/minggu |
|---|---|---|---|---|---|
| Chef | 2 | 3 | 5 | 35 | 35 ÷ 6 = **6 orang** |
| Barista | 2 | 2 | 4 | 28 | 28 ÷ 6 = **5 orang** |
| Kasir | 1 | 1 | 2 | 14 | 14 ÷ 6 = **3 orang** |
| Waiters | 1 | 3 | 4 | 28 | 28 ÷ 6 = **5 orang** |
| **Subtotal** | **6** | **9** | **15** | **105** | **19 orang** |
| Cleaning Service | ? | ? | ~2 | ~14 | **2–3 orang** |
| **TOTAL** | | | | | **21–22 orang** |

**Karyawan saat ini: 18 orang. Kekurangan ± 3–4 orang.**

Konsekuensinya, dengan 18 orang:

- 18 orang × 7 hari = 126 man-day tersedia per minggu. Kebutuhan ≈ 105 (inti) +
  14 (CS) = 119. Sisa hanya **7 man-day per minggu** untuk dibagi 18 orang.
- Artinya rata-rata setiap karyawan hanya bisa libur **0,4 hari per minggu** —
  belum sampai satu hari libur mingguan penuh.
- Dan itu **sebelum** menghitung cuti tahunan. Cuti 12 hari/orang/tahun × 18
  orang = 216 hari/tahun ≈ 0,6 orang absen setiap hari sepanjang tahun. Buffer
  ini tidak tersedia.
- Kalau 1 orang sakit mendadak, shift malam (butuh 9 orang) langsung di bawah
  kebutuhan minimum dan tidak ada penggantinya.

**Implikasi ke desain (bukan sekadar catatan HR):**

1. Modul roster **wajib** punya validasi kebutuhan minimum per shift per divisi,
   dan validasi itu harus **memberi peringatan, bukan memblokir** — karena dengan
   headcount sekarang, roster yang valid secara matematis mustahil dibuat setiap
   hari. Kalau dibuat blocking, manager tidak akan pernah bisa publish roster.
2. Approval cuti **wajib** menampilkan dampak: "kalau cuti ini disetujui, shift
   malam tanggal 12 kekurangan 1 chef". Tanpa ini manager approve buta.
3. Perlu konsep **status karyawan multi-divisi** (misal waiter yang bisa jadi
   kasir) supaya sistem bisa menyarankan pengganti. Ini yang menyelamatkan
   operasional saat headcount mepet.

**Catatan jam kerja** (perlu diverifikasi dengan konsultan ketenagakerjaan, saya
bukan penasihat hukum): shift 09:00–17:00 = 8 jam clock-to-clock, dikurangi
istirahat 1 jam = 7 jam kerja efektif. Kalau karyawan bekerja 6 hari/minggu,
totalnya 42 jam — di atas ambang 40 jam/minggu yang umum dipakai. Selisih 2 jam
itu secara aturan biasanya masuk kategori lembur. Sistem sebaiknya bisa melaporkan
akumulasi jam per minggu supaya masalah ini terlihat, bukan tersembunyi.
Shift malam yang berjalan sampai 01:00 juga masuk kategori kerja malam, yang
punya kewajiban tersendiri (transport/makan) terutama untuk pekerja perempuan.

---

## E. Gap — hal yang belum diputuskan di brief

| ID | Gap | Kenapa penting |
|---|---|---|
| **G-01** | Periode payroll tidak didefinisikan. Brief hanya bilang "gaji tanggal 21" | Kalau periode = kalender 1–31, mustahil bayar tanggal 21 (data tgl 22–31 belum ada). Ini menentukan seluruh struktur tabel payroll |
| **G-02** | Istirahat 1 jam dibayar atau tidak? | Menentukan ambang lembur, perhitungan pulang cepat, dan nilai jam kerja efektif |
| **G-03** | Tidak ada kuota cuti | Cuti tanpa saldo = tidak bisa ditolak secara objektif. Perlu entitlement + saldo + carry over |
| **G-04** | Tidak ada aturan istirahat antar shift | Tanpa ini, roster bisa menugaskan orang shift malam (selesai 01:00) lalu shift pagi (mulai 09:00) — hanya 8 jam jeda termasuk perjalanan |
| **G-05** | Boleh tidak satu orang double shift dalam satu hari? | Menentukan primary key tabel absensi harian. Sulit diubah belakangan |
| **G-06** | Status karyawan resign / masuk di tengah periode | Perlu proration gaji. Tidak disebut sama sekali |
| **G-07** | Riwayat gaji | `employees.base_salary` sekarang satu kolom. Kalau gaji naik, slip gaji bulan lalu ikut berubah |
| **G-08** | Kasbon dibayar sekali atau dicicil? | Kalau dicicil, perlu jadwal cicilan, bukan input manual tiap bulan |
| **G-09** | PPh 21 | Tidak disebut di formula. Perlu dipastikan memang tidak dipotong |
| **G-10** | THR | Tidak disebut. Kewajiban tahunan yang pasti muncul |
| **G-11** | Koreksi absensi untuk periode yang sudah dikunci | Reopen seluruh payroll, atau bayar selisih di periode berikutnya? |
| **G-12** | Alpha memotong gaji berapa? | Brief menyebut "potongan alpha" tapi tidak ada rumusnya. Per hari? Proporsional? |
| **G-13** | Approval lembur dibuat manager atau diajukan karyawan? | Bagian "LEMBUR" bilang manager yang membuat; bagian "PENGAJUAN" mencantumkan lembur sebagai pengajuan karyawan. Dua alur berbeda |
| **G-14** | Tipe mesin | Brief menyebut **Revo W-231N**, komentar di kode menyebut **Vivo W-2421M**. Revo W-231N tidak punya kamera → kolom `photo_url` selalu kosong → tidak ada bukti foto saat sengketa |

---

## F. Konflik dengan sistem eksisting

Ini yang harus diselesaikan sebelum menggambar ERD, karena semuanya menyentuh
tabel yang sudah berisi data.

| ID | Konflik | Analisis |
|---|---|---|
| **K-01** | `employees.shift_id` (shift tetap per karyawan) vs roster bulanan | Setelah ada roster, shift seseorang berbeda tiap tanggal. Kolom ini tidak boleh lagi jadi sumber kebenaran — turun pangkat jadi "shift default" untuk membantu generator roster |
| **K-02** | `employees.off_days` (libur mingguan JSON) vs libur dari roster | Dua sumber kebenaran untuk pertanyaan yang sama ("hari ini dia libur?"). Harus satu: roster. `off_days` tetap berguna sebagai *preferensi* saat men-generate roster, bukan sebagai fakta |
| **K-03** | `attendances` dirancang "sepenuhnya turunan, boleh dihapus & digenerate ulang" vs koreksi absensi manual | Begitu ada koreksi yang di-approve manager, recompute polos akan **menghapus keputusan manusia**. Ini bug paling berbahaya di seluruh rancangan kalau tidak ditangani |
| **K-04** | Aturan telat ada di `config/attendance.php` + `.env` vs "manager bisa ubah, bertingkat" | Config file tidak bisa diubah dari UI. Dan strukturnya sekarang satu blok flat (10 menit = Rp5.000 berulang), bukan bertingkat 1–10 / 11–30 / 31–60 / >60 |
| **K-05** | `attendances.deduction_amount` (rupiah) dihitung di modul absensi | Modul absensi seharusnya mencatat **fakta** (telat berapa detik), bukan **uang**. Sekarang mengubah tarif lalu recompute tanggal lama akan menimpa nominal historis. Bertabrakan langsung dengan BR-26 (payroll lock) |
| **K-06** | Role di kode: `owner` + `manager`. Brief: `manager` + `karyawan` | Tiga peran berbeda, harus diputuskan. Dan karyawan sama sekali belum punya akun |
| **K-07** | Status absensi di kode hanya 4 (hadir/telat/alpha/libur), brief minta 9 | Lebih dalam dari sekadar menambah nilai — lihat analisis di bawah |
| **K-08** | Pemasangan scan ke karyawan lewat `pin` string, tanpa periode berlaku | Kalau karyawan resign dan PIN mesinnya dipakai karyawan baru, **seluruh riwayat absensi lama berpindah ke orang baru**. Data historis rusak diam-diam |
| **K-09** | Brief minta "import data fingerprint" manual | Sistem eksisting sudah lebih baik (webhook + cron). Import manual tetap layak ditambahkan, tapi sebagai jalur darurat ketiga, bukan jalur utama |
| **K-10** | `attendances.unique(employee_id, work_date)` | Mengunci asumsi "satu orang maksimal satu shift per hari". Kalau double shift diizinkan, constraint ini harus berubah (lihat G-05) |
| **K-11** | Belum ada `deleted_at` di tabel mana pun | Brief minta soft delete. Tapi jangan diterapkan buta ke semua tabel — lihat Tahap 2 |
| **K-12** | Tidak ada audit log sama sekali | Padahal `attendances` sudah bisa berubah otomatis tiap 15 menit. Perubahan otomatis juga perlu jejak |

### Analisis K-07: "9 status" itu sebenarnya tiga dimensi berbeda

Ini temuan modeling terpenting di Tahap 1. Sembilan status di brief tidak sejajar
— tidak bisa jadi satu kolom enum:

```
Hadir · Terlambat · Pulang Cepat · Alpha · Izin · Sakit · Cuti · Libur · Lembur
```

Buktinya sederhana: seorang karyawan bisa **Hadir**, **Terlambat**, **Pulang
Cepat**, dan **Lembur** pada hari yang sama. Kalau statusnya satu kolom, manager
harus memilih satu dan tiga fakta lainnya hilang — padahal ketiganya berdampak
ke uang.

Pemisahan yang benar:

| Dimensi | Sifat | Nilai |
|---|---|---|
| **Status kehadiran** | Saling meniadakan, satu nilai | `hadir`, `alpha`, `izin`, `sakit`, `cuti`, `libur` |
| **Penyimpangan waktu** | Atribut terukur, bisa bersamaan | `late_minutes` (>0 berarti terlambat), `early_leave_minutes` (>0 berarti pulang cepat) |
| **Lembur** | Entitas tersendiri | Punya approval, jam mulai/selesai, tarif, dan siklus hidupnya sendiri. Bukan status hari |

Konsekuensi: "Terlambat" dan "Pulang Cepat" **bukan status**, melainkan turunan
dari angka. "Lembur" **bukan status**, melainkan record terpisah yang menempel
pada hari kerja. Dashboard tetap bisa menampilkan sembilan angka seperti diminta
brief — itu urusan tampilan, bukan urusan penyimpanan.

---

## G. Kebutuhan non-fungsional

| Aspek | Kebutuhan | Catatan realistis |
|---|---|---|
| Skala data | 18 karyawan × 2 scan × 365 hari ≈ 13.000 baris/tahun | Sangat kecil. MySQL/PostgreSQL biasa lebih dari cukup. **Jangan over-engineer** |
| Concurrency | 1–2 manager, 18 karyawan | Tidak ada masalah beban. Yang perlu dijaga cuma race saat generate payroll |
| Ketersediaan | Absensi tidak boleh hilang saat server down | Sudah tertangani: cron `get_attlog` menambal 60 hari ke belakang |
| Timezone | Asia/Jakarta, mesin kirim waktu lokal tanpa offset | Sudah ditangani konsisten di `config/attendance.php` |
| Keamanan | Data gaji sangat sensitif | Karyawan hanya boleh lihat datanya sendiri. Perlu authorization per-baris, bukan hanya per-halaman |
| Audit | Wajib untuk semua perubahan yang berdampak uang | Termasuk perubahan oleh sistem, bukan hanya oleh manusia |
| Mobile ready | Belum sekarang, tapi struktur harus siap | Konsekuensi: business logic wajib di service layer, bukan di controller |
| Backup | Tidak disebut brief, tapi wajib | Payroll yang hilang = masalah hukum, bukan sekadar teknis |

---

## H. Keputusan yang saya butuhkan sebelum Tahap 3 (ERD)

Empat hal ini mengubah bentuk tabel, bukan cuma isinya — jadi harus diputuskan
sekarang, bukan nanti:

1. **Periode payroll** (G-01) — rekomendasi saya: **21 s/d 20**.
2. **Istirahat dibayar atau tidak** (G-02) — menentukan ambang lembur.
3. **Jaminan libur mingguan** (bagian D) — apakah sistem harus memvalidasi 1 hari
   libur per minggu per orang, padahal headcount belum cukup?
4. **Double shift** (G-05) — boleh atau dilarang keras?

Sisanya (G-03 s/d G-14) bisa saya rancang dengan asumsi default yang aman dan
dikoreksi sambil jalan, karena tidak mengubah struktur inti.
