# Tahap 2 — Usulan Perbaikan Alur

Cafe Workforce Management System
Prasyarat: [Tahap 1 — Analisis Kebutuhan Bisnis](01-analisis-kebutuhan-bisnis.md)

Setiap usulan ditulis dengan format: **masalah → usulan → alasan → trade-off**.
Yang ditandai 🔴 wajib diselesaikan sebelum ERD dikunci. 🟡 sangat disarankan.
🟢 opsional / bisa menyusul.

---

## Bagian 1 — Perbaikan yang mengubah struktur data

### 🔴 I-01 · Pisahkan FAKTA absensi dari UANG

**Masalah** (K-05). `attendances` sekarang menyimpan `deduction_amount` dalam
rupiah, dihitung saat compute berjalan tiap 15 menit. Kalau manager mengubah
tarif potongan hari ini lalu ada recompute tanggal lama, nominal historis
tertimpa diam-diam. Ini bertabrakan langsung dengan payroll lock (BR-26).

**Usulan.**

```
attendances (Final Attendance)      →  hanya FAKTA
   late_minutes, early_leave_minutes, work_minutes, status, overtime_minutes

payroll_items                       →  hanya UANG
   rule_snapshot (JSON), qty, rate, amount
```

Modul absensi menjawab *"terlambat berapa menit"*. Modul payroll menjawab
*"terlambat itu dipotong berapa rupiah"*. Nominal dihitung **sekali** saat
payroll digenerate, lalu dibekukan bersama salinan aturan yang dipakai.

**Alasan.** Ini yang membuat BR-02 ("payroll hanya baca Final Attendance") benar-benar
bermakna, dan membuat recompute absensi aman dijalankan kapan saja. Juga sesuai
prinsip *separation of concerns*: absensi tidak tahu apa-apa soal rupiah.

**Trade-off.** Laporan "potongan hari ini" jadi butuh perhitungan on-the-fly
(karena belum ada payroll). Solusi: hitung sebagai estimasi di layer presentasi,
diberi label "estimasi", tidak disimpan.

---

### 🔴 I-02 · Status absensi jadi tiga dimensi

**Masalah** (K-07). Sembilan status di brief tidak sejajar dan tidak bisa jadi
satu enum.

**Usulan.**

| Kolom | Isi |
|---|---|
| `status` | `hadir` \| `alpha` \| `izin` \| `sakit` \| `cuti` \| `libur` (satu nilai, saling meniadakan) |
| `late_minutes` | angka; > 0 berarti "Terlambat" |
| `early_leave_minutes` | angka; > 0 berarti "Pulang Cepat" |
| `overtime_minutes` | angka, hanya terisi kalau ada approval lembur |

Dashboard tetap menampilkan 9 kartu seperti diminta brief — "Terlambat" jadi
`COUNT(late_minutes > 0)`, "Lembur" jadi `COUNT(overtime_minutes > 0)`.

**Alasan.** Kasus nyata di kafe: chef datang telat 15 menit, lalu diminta lembur
2 jam malam itu. Dengan satu enum, salah satu fakta pasti hilang.

**Trade-off.** Query dashboard sedikit lebih panjang. Tidak signifikan pada 18
karyawan.

---

### 🔴 I-03 · Roster jadi sumber kebenaran tunggal untuk jadwal

**Masalah** (K-01, K-02). Sekarang ada dua sumber jawaban untuk "hari ini dia
shift apa / libur tidak": `employees.shift_id` + `employees.off_days`. Setelah
roster ada, jadi tiga. Dua sumber kebenaran = bug yang pasti terjadi, cuma soal
waktu.

**Usulan.**

```
roster_assignments  ← SATU-SATUNYA sumber kebenaran
   employee_id, work_date, shift_id, division_id, status

employees.shift_id   → turun pangkat: "shift preferensi" (input generator roster)
employees.off_days   → turun pangkat: "preferensi libur" (input generator roster)
```

Tidak ada baris di roster untuk tanggal tertentu = libur. Bukan lagi dari
`off_days`.

`AttendanceComputer` diubah: `WorkWindow` dibangun dari shift di **roster
assignment** tanggal itu, bukan dari `employee->shift`.

**Alasan.** Roster bulanan yang bisa diedit (BR-10) mustahil hidup berdampingan
dengan shift tetap per karyawan. Menurunkan dua kolom lama jadi "preferensi"
lebih baik daripada menghapusnya — datanya tetap berguna untuk auto-generate
roster bulan depan, dan tidak ada migrasi data yang membuang informasi.

**Trade-off.** Absensi jadi bergantung pada roster. Kalau manager lupa membuat
roster, tidak ada `scheduled_in` → tidak bisa hitung telat. Mitigasi: status
`unscheduled` yang berbeda dari `alpha`, plus peringatan di dashboard mulai
tanggal 25 kalau roster bulan depan belum ada.

---

### 🔴 I-04 · Lapisan koreksi yang tidak terhapus recompute

**Masalah** (K-03). `attendances` dirancang boleh dihapus & digenerate ulang.
Begitu koreksi absensi manual masuk (BR-18), recompute akan menghapus keputusan
manager.

**Usulan.** Jangan pernah menulis hasil approval langsung ke `attendances`.
Simpan di tabel terpisah yang **append-only**, lalu terapkan sebagai lapisan
terakhir setiap kali compute berjalan:

```
attendance_logs ─┐
                 ├─> [compute] ─> [terapkan koreksi] ─> attendances (final)
attendance_      ─┘                      ▲
  adjustments  (append-only, hasil approval) ┘
```

Sifat penting: recompute tetap **idempoten** (dijalankan berapa kali pun hasilnya
sama), tapi keputusan manusia tidak pernah hilang karena ia bagian dari input,
bukan bagian dari output.

**Alasan.** Ini mempertahankan properti terbaik dari desain eksisting (bisa
dibangun ulang dari nol) sambil memenuhi kebutuhan koreksi manual. Alternatifnya
— mengunci baris yang sudah dikoreksi — membuat sistem punya dua kelas baris
dengan aturan berbeda, jauh lebih rawan.

**Trade-off.** Satu tabel dan satu langkah pipeline tambahan. Sepadan.

---

### 🔴 I-05 · Aturan pindah dari config file ke database, dengan masa berlaku

**Masalah** (K-04). Aturan telat ada di `config/attendance.php` dan `.env`.
Manager tidak bisa mengubahnya dari UI, dan strukturnya flat, bukan bertingkat.

**Usulan.**

```
rule_sets           id, type (late|early_leave|overtime|bpjs|deduction),
                    effective_from, effective_to, created_by
rule_tiers          rule_set_id, min_minutes, max_minutes,
                    calc_type (flat|percent|per_hour), amount
```

Contoh BR-05 jadi 4 baris `rule_tiers`: 1–10, 11–30, 31–60, 61–∞.

Aturan **tidak pernah diubah di tempat**. Mengubah tarif = membuat rule_set baru
dengan `effective_from` baru. Yang lama tetap ada sebagai riwayat.

**Alasan.** Tanpa masa berlaku, mengubah tarif hari ini akan mengubah perhitungan
bulan lalu. Dengan `effective_from`, pertanyaan "kenapa potongan Juli beda dengan
Agustus" selalu bisa dijawab. `config/attendance.php` tetap dipakai sebagai nilai
default seeder, jadi kerja yang sudah ada tidak terbuang.

**Trade-off.** Lebih rumit dari `config()`. Mitigasi: satu service
`RuleResolver::for($type, $date)` yang menyembunyikan kerumitan, dan hasilnya
di-cache — datanya jarang berubah.

---

### 🔴 I-06 · Periode payroll sebagai entitas, bukan bulan kalender

**Masalah** (G-01). "Gaji tanggal 21" tidak mungkin berarti periode 1–31.

**Usulan.**

```
payroll_periods   id, code (2026-08), start_date (2026-07-21), end_date (2026-08-20),
                  pay_date (2026-08-21), status, locked_at, locked_by
```

Status: `draft → generated → approved → locked` (+ `reopened`).

**Roster tetap kalender** (1–31) karena manager berpikir dalam bulan kalender
saat menyusun jadwal (BR-09). Periode payroll adalah entitas terpisah yang
memotong lintas bulan. Keduanya tidak perlu sama.

**Alasan.** Semua penguncian, semua laporan gaji, dan semua snapshot menempel ke
periode — bukan ke bulan kalender. Kalau nanti tanggal gajian berubah jadi
tanggal 25, cukup ubah data, bukan kode.

**Trade-off.** Laporan absensi (per bulan kalender) dan laporan gaji (per periode)
tidak akan pernah cocok angkanya. Ini harus dijelaskan eksplisit di UI, jangan
dibiarkan jadi kebingungan.

---

### 🔴 I-07 · Pemetaan karyawan ↔ PIN mesin harus punya masa berlaku

**Masalah** (K-08). Kalau karyawan resign lalu PIN mesinnya dipakai karyawan
baru, seluruh riwayat absensi lama otomatis berpindah ke orang baru. Diam-diam,
tanpa error. Dengan mesin 18 slot dan turnover kafe yang biasanya tinggi, ini
bukan skenario teoretis.

**Usulan.**

```
employee_devices   employee_id, cloud_id, pin, valid_from, valid_to
```

Pencocokan scan → karyawan memakai pemetaan yang **berlaku pada tanggal scan
itu**, bukan pemetaan hari ini.

**Alasan.** Riwayat absensi adalah dasar penghitungan gaji yang sudah dibayar.
Data historis yang bisa berubah sendiri adalah cacat integritas paling serius
dalam sistem penggajian.

**Trade-off.** Satu join tambahan di query yang paling sering dipakai. Pada skala
ini tidak terasa.

---

### 🟡 I-08 · Riwayat gaji, bukan satu kolom

**Masalah** (G-07). `employees.base_salary` satu kolom. Naik gaji = slip gaji
lama ikut berubah kalau pernah di-regenerate.

**Usulan.** `employee_salaries (employee_id, component_id, amount, effective_from)`.
Sekaligus menyiapkan komponen selain gaji pokok (tunjangan jabatan, uang makan,
transport) tanpa menambah kolom baru tiap kali.

**Trade-off.** Menambah satu tabel untuk kebutuhan yang belum ada sekarang.
Tetap saya rekomendasikan: menambahkannya belakangan berarti migrasi data gaji
yang sudah terpakai — pekerjaan yang jauh lebih mahal dan berisiko.

---

### 🟡 I-09 · Kuota cuti

**Masalah** (G-03). Cuti tanpa saldo tidak bisa ditolak secara objektif.

**Usulan.** `leave_types` (cuti tahunan, sakit berbayar, izin tidak berbayar, cuti
melahirkan…) + `leave_balances` (per karyawan per tahun: entitlement, terpakai,
sisa, carry over).

**Alasan.** Ini juga yang membedakan Izin, Sakit, dan Cuti secara bermakna. Tanpa
kuota, ketiganya cuma label berbeda tanpa konsekuensi berbeda — padahal
konsekuensi gajinya berbeda (cuti dibayar, izin biasanya tidak).

---

### 🟡 I-10 · Divisi + kompetensi lintas divisi

**Masalah.** Divisi belum ada. Dan dengan kekurangan 3–4 orang (Tahap 1 bagian D),
kafe pasti sering mengandalkan orang yang merangkap.

**Usulan.**

```
divisions              id, name, code
employee_divisions     employee_id, division_id, is_primary, competency_level
staffing_requirements  shift_id, division_id, day_type, required_count
```

`staffing_requirements` membuat BR-11 & BR-12 jadi **data**, bukan angka
hard-coded. Kalau kafe ramai di akhir pekan dan butuh 4 waiter, manager tinggal
mengubah data.

**Alasan.** `employee_divisions` sebagai tabel pivot (bukan `employees.division_id`)
memungkinkan sistem menjawab: "shift malam tanggal 12 kurang 1 kasir — siapa yang
bisa mengisi?" Ini yang menyelamatkan operasional saat headcount mepet.

**Trade-off.** Lebih rumit dari satu kolom `division_id`. Dibenarkan oleh temuan
kapasitas di Tahap 1.

---

## Bagian 2 — Perbaikan alur proses

### 🔴 I-11 · Lembur: dua titik approval, bukan satu

**Masalah** (BR-13, G-13). Alur di brief: manager membuat lembur → approve →
karyawan bekerja → fingerprint → payroll hitung. Masalahnya, approval terjadi
**sebelum** kerja, tapi yang dibayar adalah **realisasi**. Kalau lembur disetujui
3 jam tapi orangnya pulang setelah 1 jam, brief tidak menjelaskan mana yang
dibayar. Sebaliknya, kalau ada kerusakan mendadak jam 23:00 dan manager tidak
sempat membuat approval, kerja itu jadi tidak dibayar sama sekali (BR-14).

**Usulan.**

```
overtime_requests   rencana: tanggal, jam mulai, jam selesai, alasan, status
        │  approve (sebelum kerja)
        ▼
   karyawan bekerja → fingerprint
        │
        ▼
overtime_records    realisasi: jam aktual dari scan, durasi diakui, status
        │  konfirmasi manager (setelah kerja)
        ▼
     payroll
```

Yang dibayar = **min(disetujui, aktual)** secara default, dan manager bisa
menyetujui lebih dengan alasan tertulis.

Tambahan: izinkan **approval susulan** (retroaktif) dengan penanda `is_backdated`
+ alasan wajib + jejak audit. Ini bukan pelemahan BR-14 — aturan "tanpa approval
bukan lembur" tetap berlaku, hanya saja approval boleh diberikan setelahnya untuk
kejadian darurat, dan setiap kali itu terjadi tercatat jelas.

**Trade-off.** Dua tabel, bukan satu. Alternatif lebih sederhana: satu tabel
dengan kolom rencana dan realisasi berdampingan. Saya tidak menyarankan itu
karena satu rencana lembur bisa punya realisasi berbeda per karyawan (manager
menjadwalkan lembur untuk 3 chef, dua pulang jam 2, satu jam 3).

---

### 🔴 I-12 · Koreksi setelah payroll terkunci: adjustment, bukan reopen

**Masalah** (G-11, BR-26). Kalau karyawan lapor tanggal 25 bahwa absensi tanggal
15 salah (periode sudah terkunci), Reopen Payroll berarti membatalkan slip gaji
yang sudah diterima 18 orang. Terlalu berat untuk kesalahan satu orang.

**Usulan.** Dua jalur berbeda:

| Situasi | Jalur |
|---|---|
| Kesalahan kecil, 1–2 orang, dampak kecil | **Adjustment**: koreksi tetap dicatat di tanggal aslinya, selisih uangnya masuk sebagai baris "penyesuaian periode lalu" di payroll periode **berikutnya** |
| Kesalahan sistemik (rule salah, import gagal, banyak orang) | **Reopen**: buka kunci periode, hitung ulang, terbitkan slip revisi bernomor versi |

**Alasan.** Ini praktik standar akuntansi penggajian — periode yang sudah ditutup
tidak dibuka untuk koreksi rutin. Slip gaji yang sudah diterima karyawan tidak
boleh berubah angkanya secara diam-diam.

**Trade-off.** Slip gaji jadi punya baris yang merujuk periode lain, butuh
penjelasan di UI. Jauh lebih baik daripada slip yang bisa berubah retroaktif.

---

### 🟡 I-13 · Validasi roster: peringatan, bukan penghalang

**Masalah** (Tahap 1 bagian D). Dengan 18 orang, roster yang memenuhi semua
kebutuhan minimum + libur mingguan **secara matematis mustahil**. Validasi yang
memblokir akan membuat manager tidak pernah bisa publish roster.

**Usulan.** Validasi berjenjang:

| Level | Contoh | Perilaku |
|---|---|---|
| **Error** (blokir) | Orang yang sama di dua shift bertabrakan; ditugaskan saat cuti disetujui | Tidak bisa disimpan |
| **Warning** (boleh lanjut, wajib sadar) | Kurang 1 chef di shift malam; jeda antar shift < 10 jam; kerja 10 hari berturut-turut | Bisa disimpan, muncul ringkasan sebelum publish |
| **Info** | Tidak sesuai preferensi libur karyawan | Ditampilkan saja |

**Alasan.** Sistem yang memaksakan aturan yang tidak bisa dipenuhi akan
ditinggalkan penggunanya — manager akan kembali ke Excel. Sistem yang menunjukkan
konsekuensi dengan jujur akan dipakai.

---

### 🟡 I-14 · Aturan jeda antar shift

**Masalah** (G-04). Shift malam selesai 01:00, shift pagi mulai 09:00 — hanya 8
jam, termasuk perjalanan pulang-pergi. Ini resep kelelahan, dan di kafe berarti
risiko keselamatan (dapur, alat panas).

**Usulan.** Rule configurable `min_rest_hours` (default 10) dan
`max_consecutive_days` (default 6), dicek saat menyusun roster dan saat approve
tukar shift. Level: Warning (lihat I-13).

---

### 🟡 I-15 · Tukar shift butuh validasi, bukan hanya persetujuan

**Masalah** (BR-19). Alur di brief hanya persetujuan berjenjang. Tidak ada
pengecekan apakah pertukarannya masuk akal.

**Usulan.** Saat rekan menerima dan saat manager approve, sistem mengecek:
kompetensi divisi cocok, tidak melanggar jeda antar shift, tidak membuat shift
lain kekurangan orang, tanggalnya belum lewat, periode payroll belum terkunci.
Tambahkan juga masa kedaluwarsa pengajuan (default: H-1 sebelum tanggal shift) —
tanpa itu, pengajuan menggantung bisa mengubah roster di menit terakhir.

---

### 🟡 I-16 · Proses tutup hari yang eksplisit

**Masalah.** Alpha sekarang lahir sebagai efek samping compute tiap 15 menit,
dan hanya "belum bisa disimpulkan" kalau jendela shift belum selesai. Tidak ada
titik waktu yang jelas kapan sebuah hari dinyatakan final.

**Usulan.** Job `attendance:close-day` jam **06:00** (setelah jendela shift malam
tutup jam 05:00) yang: memfinalkan status hari sebelumnya, menetapkan alpha,
menandai `is_closed`, dan mengirim notifikasi ke manager kalau ada anomali (scan
tanpa pasangan, PIN tidak dikenal, orang terjadwal tanpa scan sama sekali).

**Alasan.** Memberi satu titik waktu yang bisa dijawab dengan pasti: "data
tanggal 5 sudah final sejak 6 Agustus 06:00". Compute tiap 15 menit tetap jalan
untuk dashboard hari berjalan, tapi sifatnya sementara.

---

### 🟢 I-17 · Halaman rekonsiliasi absensi

**Usulan.** Satu halaman khusus yang menampilkan semua anomali harian: scan
dengan PIN tidak terdaftar, hari dengan scan masuk tanpa scan pulang, karyawan
terjadwal tanpa scan, scan ganda dalam 2 menit.

**Alasan.** `attendance_logs` sengaja menerima scan dari PIN yang belum terdaftar
supaya tidak ada data hilang — tapi saat ini tidak ada yang memberitahu manager
bahwa data itu menggantung. Ini juga pintu masuk paling alami untuk membuat
koreksi absensi.

---

### 🟢 I-18 · Import manual sebagai jalur ketiga

**Usulan.** Pertahankan webhook + cron sebagai jalur utama (K-09), tambahkan
upload file export mesin sebagai jalur darurat dengan `source = 'import'`. Semua
masuk ke `attendance_logs` yang sama, dan anti-duplikat `scan_minute` yang sudah
ada otomatis melindungi dari dobel.

**Alasan.** Memenuhi permintaan brief tanpa menurunkan kualitas sistem yang sudah
ada. Berguna saat internet kafe mati berhari-hari melewati retensi 60 hari mesin.

---

## Bagian 3 — Fondasi teknis

### 🔴 I-19 · Modul pengajuan: satu tabel induk + tabel detail per jenis

**Masalah** (BR-17). Enam jenis pengajuan dengan data yang sangat berbeda: cuti
punya rentang tanggal, tukar shift punya rekan dan dua tanggal, koreksi absensi
punya jam yang diusulkan dan lampiran.

**Tiga pilihan:**

| Pendekatan | Kelebihan | Kekurangan |
|---|---|---|
| Satu tabel + kolom JSON | Paling sederhana | Tidak bisa divalidasi di level DB, query per jenis payah |
| Tabel terpisah per jenis | Paling ketat | Alur approval & inbox duplikat 6 kali |
| **Induk + detail** ✅ | Approval, audit, notifikasi, inbox jadi satu jalur; detail tetap ketat & ber-FK | Satu join |

**Usulan.**

```
requests           id, type, requester_id, status, submitted_at,
                   decided_by, decided_at, decision_note
    ├── leave_requests           request_id, leave_type_id, start_date, end_date, ...
    ├── overtime_requests        request_id, work_date, planned_start, planned_end, ...
    ├── shift_swap_requests      request_id, partner_id, my_date, partner_date, partner_accepted_at
    └── attendance_corrections   request_id, work_date, proposed_in, proposed_out, evidence_path
```

Satu inbox, satu mesin state, satu jalur audit — tapi setiap jenis tetap punya
foreign key dan validasi sendiri.

---

### 🔴 I-20 · Audit log lewat event, bukan panggilan manual

**Masalah** (BR-29, K-12). Audit log yang ditulis manual di setiap controller
pasti akan terlewat di suatu tempat.

**Usulan.** `audit_logs` polymorphic (`auditable_type`, `auditable_id`,
`old_values` JSON, `new_values` JSON, `actor_type` manusia/sistem, `ip`,
`user_agent`), diisi otomatis lewat model event dari sebuah trait `Auditable`.

Penting: `actor_type` harus membedakan perubahan oleh **manusia** dan oleh
**sistem** (cron compute). Tanpa itu, log akan penuh perubahan otomatis tiap 15
menit dan yang penting jadi tenggelam.

---

### 🟡 I-21 · Soft delete selektif, bukan menyeluruh

**Masalah** (BR/K-11). "Soft delete" sebagai prinsip umum sering diterapkan buta
ke semua tabel dan justru berbahaya.

**Usulan.**

| Jenis tabel | Perlakuan |
|---|---|
| Master (employees, divisions, shifts, rules) | Soft delete ✅ — masih dirujuk data historis |
| Transaksi (attendances, payroll, requests) | **Tidak dihapus sama sekali**, pakai status (`cancelled`, `void`) |
| Turunan (attendance_logs → attendances) | Boleh hard delete, karena bisa dibangun ulang |

Untuk karyawan resign, yang benar bukan soft delete melainkan `employment_status`
+ `resigned_at` — karena dia bukan "terhapus", dia masih pernah ada dan slip
gajinya masih harus bisa dibuka.

---

### 🟡 I-22 · Notifikasi pakai pola outbox

**Usulan** (BR-30).

```
notification_templates   code, channel (db|email|whatsapp), subject, body_template
notifications            user_id, template_code, payload JSON, read_at
notification_deliveries  notification_id, channel, status, attempts, sent_at, error
```

Sekarang hanya channel `db` (lonceng di aplikasi) yang diimplementasi. Saat
WhatsApp ditambahkan nanti, cukup menambah driver — tidak ada tabel yang berubah.

---

### 🟡 I-23 · Login karyawan: `users` 1:1 opsional ke `employees`

**Masalah** (K-06). Karyawan belum punya akun. Role di kode `owner`/`manager`,
di brief `manager`/`karyawan`.

**Usulan.** `users.employee_id` nullable unique. Manager/owner boleh tidak punya
data karyawan; karyawan wajib punya. Role di DB dibiarkan tiga
(`owner`, `manager`, `karyawan`) — owner = manager + kelola akun. UI tetap
memperlihatkan dua peran seperti diminta brief.

**Alasan.** Mempertahankan `EnsureUserIsOwner` yang sudah ada dan sudah diuji,
tanpa menambah kerumitan yang terlihat pengguna. Biaya menyimpan peran ketiga
hampir nol; biaya menghapusnya lalu membutuhkannya lagi tidak nol.

Tambahan penting: otorisasi harus **per baris**, bukan per halaman. Karyawan A
membuka `/slip-gaji/123` milik karyawan B harus 403, bukan sekadar tidak ada
tautannya di menu.

---

### 🟢 I-24 · `branch_id` sejak awal

**Usulan.** Tambahkan `branch_id` (default 1) di employees, roster, attendance,
payroll. Satu cabang sekarang, tapi kafe yang berhasil biasanya buka cabang
kedua.

**Trade-off.** Menambah kolom yang belum terpakai. Biayanya sangat kecil
sekarang; menambahkannya setelah ada data payroll adalah migrasi besar.

---

### 🟢 I-25 · Kasbon dengan jadwal cicilan

**Usulan** (G-08). `cash_advances` + `cash_advance_installments` (per periode
payroll). Payroll otomatis menarik cicilan yang jatuh tempo, bukan manager
mengetik ulang tiap bulan — yang pasti akan terlewat suatu saat.

---

### 🟢 I-26 · Siapkan tempat untuk PPh 21 & THR

**Usulan** (G-09, G-10). Jangan diimplementasi sekarang. Cukup pastikan
`payroll_items` punya `category` (earning/deduction/statutory) dan `is_taxable`,
sehingga pajak bisa ditambahkan tanpa mengubah struktur.

---

## Ringkasan dampak

| Usulan | Dampak ke kode eksisting |
|---|---|
| I-01 Fakta vs uang | Ubah `AttendanceComputer`, `LateCalculator`, kolom `attendances` |
| I-02 Status 3 dimensi | Ubah kolom `attendances`, dashboard |
| I-03 Roster sumber kebenaran | Ubah `AttendanceComputer` + `WorkWindow` (ambil shift dari roster) |
| I-04 Lapisan koreksi | Tambah 1 langkah di akhir pipeline compute |
| I-05 Rule di DB | `config/attendance.php` jadi seeder default |
| I-07 Pemetaan device | Ubah cara `logsIn()` mencocokkan scan |
| Sisanya | Modul baru, tidak menyentuh kode absensi yang ada |

Yang **tidak berubah sama sekali**: `device_callbacks`, `attendance_logs`,
`FingerspotClient`, `AttlogParser`, `AttlogSynchronizer`, webhook, dan seluruh
strategi anti-duplikat. Pipeline masuknya data sudah benar dan tidak disentuh.
