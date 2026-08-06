# Tahap 4 — Struktur Database

Cafe Workforce Management System
Prasyarat: [Tahap 3 — ERD](03-erd.md) · [Log Keputusan](00-keputusan.md)

Tahap 3 menetapkan *entitas dan relasi*. Dokumen ini menetapkan *kolom, tipe,
indeks, constraint, dan rencana migrasi* untuk tabel yang sudah berisi data.

---

## 0. Temuan: database saat ini SQLite

```
DB_CONNECTION=sqlite    APP_TIMEZONE=Asia/Jakarta
Laravel 13.24  ·  PHP 8.5.6  ·  queue/cache/session = database
```

Ini perlu dibahas sebelum satu migration pun ditulis, karena beberapa keputusan
struktur bergantung padanya.

**SQLite untuk kafe 18 orang sebenarnya masuk akal.** Volume tulis sangat kecil
(±40 scan/hari), backup cuma menyalin satu file, dan tidak ada server database
yang perlu diurus. Jangan pindah ke MySQL hanya karena "MySQL terdengar lebih
serius".

**Tapi ada tiga risiko nyata yang harus ditangani:**

| Risiko | Kenapa berbahaya di sini | Penanganan |
|---|---|---|
| SQLite hanya izinkan **satu penulis** pada satu waktu | `queue`, `cache`, dan `session` semuanya di database yang sama, ditambah cron tiap menit dan webhook mesin yang datang kapan saja. Generate payroll yang berjalan beberapa detik akan memblokir insert webhook → mesin dapat error → scan hilang | WAL + `busy_timeout` **sudah aktif** (§4). Yang masih perlu: generate payroll dipecah per karyawan, bukan satu transaksi raksasa |
| Tipe kolom tidak ditegakkan | `decimal` diperlakukan sebagai teks/angka bebas. Selisih pembulatan di gaji tidak akan ketahuan database | Semua uang **integer rupiah** (sudah jadi keputusan §1.4). Validasi di aplikasi, bukan mengandalkan DB |
| `ALTER TABLE` terbatas | Beberapa perubahan memaksa Laravel membuat ulang tabel diam-diam. Pada tabel absensi yang sudah berisi data, ini titik paling rawan kehilangan data | Migrasi bertahap §3, dengan backup file DB sebelum tiap batch |

Kehilangan scan akibat lock sebenarnya **sudah dilindungi** sistem eksisting:
cron `get_attlog` menarik ulang 2 hari terakhir setiap jam 02:00, dan mesin
menyimpan 60 hari. Jadi scan yang gagal masuk saat lock akan ditambal maksimal
dalam 24 jam. Ini keuntungan tak terduga dari desain dua jalur yang sudah ada.

**Rekomendasi:** tetap SQLite sekarang, dengan WAL aktif. Pindah ke MySQL/MariaDB
kalau salah satu terjadi: (a) cabang kedua dibuka, (b) karyawan > 50, (c) muncul
error `database is locked` di log lebih dari sekali seminggu.

Konsekuensinya untuk dokumen ini: **seluruh skema ditulis portabel** — tidak
memakai fitur yang hanya ada di satu engine, sehingga pindah engine nanti cukup
`migrate:fresh` di database baru + impor data, tanpa menulis ulang migration.

---

## 1. Konvensi

### 1.1 Penamaan

| Aturan | Contoh |
|---|---|
| Tabel: `snake_case`, jamak | `roster_assignments` |
| Foreign key: `<singular>_id` | `employee_id`, `payroll_period_id` |
| Boolean: awalan `is_` / `has_` | `is_active`, `has_adjustment` |
| Timestamp: akhiran `_at` | `check_in_at`, `locked_at` |
| Tanggal murni: akhiran `_date` | `work_date`, `pay_date` |
| Durasi: satuan eksplisit | `late_minutes`, `break_minutes` |
| Uang: tanpa akhiran, selalu integer | `amount`, `take_home_pay` |

Bahasa Inggris untuk nama tabel/kolom (konsisten dengan yang sudah ada:
`employees`, `attendance_logs`), bahasa Indonesia untuk nilai enum yang dilihat
pengguna (`hadir`, `alpha`, `telat`) — juga mengikuti yang sudah ada.

### 1.2 Primary key

`bigIncrements` (auto-increment) di semua tabel. **Bukan UUID.**

*Alasan:* satu server, satu database, tidak ada replikasi atau merge data antar
node. UUID hanya menambah 12 byte per baris dan memperlambat index tanpa
memberikan apa pun di skala ini.

*Untuk yang perlu ditampilkan ke pengguna* — pengajuan dan slip gaji — dipakai
kolom `code` yang bisa dibaca manusia (`REQ-2026-08-0001`, `SLIP-2026-08-014`),
bukan ID mentah. Ini juga mencegah karyawan menebak `/pengajuan/124` untuk
mengintip pengajuan orang lain (walau otorisasi per baris tetap wajib, INV-12).

### 1.3 Enum

Disimpan sebagai `string`, **bukan** tipe `enum` database.

*Alasan:* mengubah tipe enum di SQLite berarti membuat ulang tabel. Dengan
string, menambah status baru cukup menambah nilai di PHP enum class. Validasi
tetap ketat karena semua penulisan lewat service layer + PHP enum. Ini
melanjutkan pola yang sudah dipakai (`users.role`, `attendances.status`).

### 1.4 Uang

Semua nominal: `bigInteger` **rupiah penuh**, tanpa desimal.

*Alasan:* float menghasilkan selisih satu rupiah yang mustahil dijelaskan ke
karyawan, dan `decimal` tidak ditegakkan SQLite. Kafe tidak menggajikan sen.
Yang perlu pecahan hanya *hari cuti* (0,5 hari) dan *pengali lembur* (1,5) —
keduanya `decimal(8,2)`, dan tidak pernah jadi hasil akhir uang.

### 1.5 Waktu

| Jenis | Tipe | Catatan |
|---|---|---|
| Momen kejadian | `timestamp` | Waktu lokal Asia/Jakarta, mengikuti konvensi yang sudah ada di `attendance_logs` |
| Tanggal kerja | `date` | `work_date` tidak punya jam. Jangan pernah bandingkan dengan string — pakai objek Carbon (bug ini sudah pernah kejadian, lihat komentar di `AttendanceComputer`) |
| Jam shift | `time` | Tanpa tanggal |

### 1.6 Soft delete — selektif

Mengikuti §4 Tahap 3. `deleted_at` **hanya** di master data. Tabel transaksi
memakai kolom `status`. Tabel turunan boleh dihapus keras.

*Alasan:* soft delete menyeluruh berarti setiap query harus ingat menyaring
`deleted_at`, dan yang lupa akan menghasilkan slip gaji dengan karyawan hantu.
Lebih baik dibatasi ke tempat yang benar-benar butuh.

### 1.7 Strategi indeks

Tiga aturan:
1. Setiap FK diindeks (tidak otomatis di SQLite).
2. Indeks komposit mengikuti pola query nyata, kolom paling selektif di depan.
3. Tidak ada indeks "untuk jaga-jaga" — indeks yang tidak dipakai memperlambat
   tulis dan membesarkan file.

### 1.8 `shift_key` — kolom bantu untuk double shift

Konsekuensi D-04. Karena `shift_id` boleh NULL (baris libur) dan **semua engine
memperlakukan NULL sebagai selalu berbeda** di unique index, satu karyawan bisa
punya banyak baris libur di tanggal sama tanpa ada yang mencegah.

```
shift_key = shift_id ?? 0
unique (employee_id, work_date, shift_key)
```

*Pilihan implementasi:*

| Cara | Nilai plus | Nilai minus |
|---|---|---|
| **Kolom biasa, diisi model** ✅ | Portabel penuh, `ADD COLUMN` biasa, mudah dipahami | Bisa melenceng kalau ada yang menulis lewat SQL mentah |
| Generated column | Dijamin database | SQLite tidak bisa `ADD COLUMN` bertipe STORED ke tabel yang sudah ada — persis situasi kita di `attendances` |

Dipilih kolom biasa, diisi lewat trait `HasShiftKey` pada event `saving`, dan
dikunci satu test yang memastikan tidak ada baris dengan `shift_key` melenceng.

---

## 2. Spesifikasi tabel

Legenda: **PK** primary key · **FK** foreign key · **UK** bagian unique key ·
**IX** diindeks · ⟳ kolom baru pada tabel yang sudah ada · ✖ kolom yang dihapus

### 2.1 Organisasi & Identitas

#### `branches`

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| id | bigint PK | | | |
| code | string(16) UK | | | `pusat` |
| name | string(100) | | | |
| address | text | ✔ | | |
| timezone | string(64) | | `Asia/Jakarta` | Disiapkan untuk cabang beda zona |
| is_active | boolean | | `true` | |
| deleted_at, timestamps | | | | |

Terisi satu baris dari seeder. Ada sejak awal karena menambahkan `branch_id` ke
tabel yang sudah berisi payroll adalah migrasi besar (I-24).

#### `users` ⟳ (tabel sudah ada)

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| id, name, email UK, password, remember_token, timestamps | | | | Sudah ada |
| role | string(16) | | `manager` | Sudah ada. Nilai bertambah: `karyawan` |
| is_active | boolean | | `true` | Sudah ada |
| ⟳ employee_id | bigint FK UK | ✔ | | 1:1 opsional ke `employees` |
| ⟳ last_login_at | timestamp | ✔ | | Untuk audit login |
| ⟳ must_change_password | boolean | | `false` | Akun karyawan dibuat manager dengan password sementara |
| ⟳ deleted_at | timestamp | ✔ | | |

- `unique(employee_id)` — satu karyawan maksimal satu akun
- FK `employee_id` → `employees.id` `nullOnDelete`

#### `employees` ⟳ (tabel sudah ada, 18 baris)

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| id, name, phone, timestamps | | | | Sudah ada |
| pin_device | string(32) | | | Sudah ada. **Berubah makna**: jadi cermin baca-saja dari `employee_devices` yang aktif. Unique dilepas |
| shift_id → ⟳ `default_shift_id` | bigint FK | ✔ | | **Rename**. Turun pangkat jadi preferensi (D-20) |
| off_days → ⟳ `preferred_off_days` | json | ✔ | | **Rename**. Turun pangkat jadi preferensi |
| base_salary | bigint | | `0` | ✖ **Dihapus** setelah dipindah ke `employee_salaries` |
| is_active | boolean | | `true` | Dipertahankan; diturunkan dari `employment_status` |
| joined_at | date | ✔ | | Sudah ada |
| ⟳ branch_id | bigint FK | | `1` | |
| ⟳ employee_no | string(32) UK | | | Nomor induk internal. Berbeda dari PIN mesin |
| ⟳ employment_status | string(16) | | `active` | `active` / `resigned` / `suspended` |
| ⟳ resigned_at | date | ✔ | | |
| ⟳ email | string(120) | ✔ | | Untuk akun & notifikasi |
| ⟳ deleted_at | timestamp | ✔ | | |

- IX: `employment_status`, `branch_id`, `default_shift_id`
- **Kenapa `is_active` dipertahankan padahal ada `employment_status`:** dipakai
  di banyak query dan command yang sudah ada. Dijaga tetap sinkron lewat model.
  Menghapusnya sekarang berarti menyentuh belasan file tanpa manfaat langsung.

#### `employee_devices` (baru)

| Kolom | Tipe | Null | Keterangan |
|---|---|---|---|
| id | bigint PK | | |
| employee_id | bigint FK IX | | |
| cloud_id | string(64) | | SN mesin. Disiapkan untuk mesin kedua |
| pin | string(32) | | Employee ID di mesin |
| valid_from | date | | |
| valid_to | date | ✔ | `null` = masih berlaku |
| note | string(255) | ✔ | Alasan pemindahan PIN |
| timestamps, deleted_at | | | |

- IX komposit: `(cloud_id, pin, valid_from)` — jalur pencocokan scan
- IX: `(employee_id, valid_from)`
- **INV-08** (periode tidak boleh tumpang tindih untuk `(cloud_id, pin)` yang
  sama) tidak bisa diwakili unique constraint — ditegakkan service layer

#### `divisions` (baru)

| Kolom | Tipe | Null | Keterangan |
|---|---|---|---|
| id | bigint PK | | |
| code | string(32) UK | | `chef`, `barista`, `kasir`, `waiter`, `cleaning` |
| name | string(64) | | |
| color | string(7) | ✔ | Warna di grid roster — kebutuhan UI nyata |
| sort_order | smallint | | |
| is_active | boolean | | |
| timestamps, deleted_at | | | |

#### `employee_divisions` (baru, pivot)

| Kolom | Tipe | Null | Keterangan |
|---|---|---|---|
| id | bigint PK | | |
| employee_id | bigint FK UK | | |
| division_id | bigint FK UK | | |
| is_primary | boolean | | Tepat satu per karyawan (ditegakkan service) |
| competency_level | tinyint | | 1 = bisa membantu, 2 = cakap, 3 = mahir |
| timestamps | | | |

- `unique(employee_id, division_id)`
- IX: `(division_id, competency_level)` — untuk mencari pengganti

---

### 2.2 Konfigurasi

#### `shifts` ⟳ (tabel sudah ada, 2 baris)

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| id, name, start_time, end_time, is_active, timestamps | | | | Sudah ada |
| ⟳ code | string(16) UK | | | `pagi`, `malam` |
| ⟳ crosses_midnight | boolean | | `false` | Diturunkan dari jam, disimpan agar bisa di-query |
| ⟳ break_minutes | smallint | | `60` | |
| ⟳ is_break_paid | boolean | | `true` | D-02 |
| ⟳ window_before_hours | tinyint | | `4` | Pindah dari `config/attendance.php` |
| ⟳ window_after_hours | tinyint | | `4` | |
| ⟳ overtime_starts_after_minutes | smallint | | `0` | Menit setelah `end_time` sebelum lembur mulai dihitung |
| ⟳ color | string(7) | ✔ | | |
| ⟳ deleted_at | timestamp | ✔ | | |

Memindahkan `window_*` ke shift memungkinkan shift malam punya toleransi berbeda
dari shift pagi — sekarang keduanya terkunci pada satu nilai global.

#### `staffing_requirements` (baru)

| Kolom | Tipe | Null | Keterangan |
|---|---|---|---|
| id | bigint PK | | |
| branch_id, shift_id, division_id | bigint FK | | |
| day_type | string(16) | | `all` / `weekday` / `weekend` / `holiday` |
| required_count | tinyint | | |
| effective_from | date | | |
| effective_to | date | ✔ | |
| timestamps | | | |

- IX: `(shift_id, division_id, day_type, effective_from)`
- Seeder mengisi BR-11 & BR-12 sebagai data, bukan konstanta di kode

#### `holidays` ⟳ (tabel sudah ada)

| Kolom | Keterangan |
|---|---|
| date UK, name, is_closed, timestamps | Sudah ada |
| ⟳ branch_id | FK, default 1 |
| ⟳ is_national | Membedakan tanggal merah dari "kafe tutup" karena renovasi |

`unique(date)` menjadi `unique(branch_id, date)`.

#### `rule_sets` (baru)

| Kolom | Tipe | Null | Keterangan |
|---|---|---|---|
| id | bigint PK | | |
| branch_id | bigint FK | | |
| type | string(24) IX | | `late` / `early_leave` / `overtime` / `absent` / `bpjs` |
| name | string(100) | | "Potongan Telat 2026" |
| effective_from | date IX | | |
| effective_to | date | ✔ | `null` = masih berlaku |
| is_active | boolean | | |
| created_by | bigint FK users | | |
| note | text | ✔ | Alasan perubahan aturan |
| timestamps | | | |

- IX: `(type, effective_from, effective_to)`
- **INV-09**: dua rule_set bertipe sama tidak boleh tumpang tindih masa berlaku —
  service layer

#### `rule_tiers` (baru)

| Kolom | Tipe | Null | Keterangan |
|---|---|---|---|
| id | bigint PK | | |
| rule_set_id | bigint FK IX | | `cascadeOnDelete` |
| min_value | int | | Inklusif |
| max_value | int | ✔ | Inklusif, `null` = tak terbatas |
| unit | string(16) | | `minute` / `hour` / `day` |
| calc_type | string(24) | | `flat` / `percent_of_base` / `hourly_multiplier` / `daily_rate` |
| value | decimal(12,2) | | Rupiah untuk `flat`, pengali untuk sisanya |
| label | string(64) | ✔ | "Telat 11–30 menit" — ikut ke slip gaji |
| sort_order | smallint | | |

Contoh isi untuk BR-05:

| min | max | unit | calc_type | value | label |
|---|---|---|---|---|---|
| 1 | 10 | minute | flat | 5000 | Telat 1–10 menit |
| 11 | 30 | minute | flat | 15000 | Telat 11–30 menit |
| 31 | 60 | minute | flat | 30000 | Telat 31–60 menit |
| 61 | *null* | minute | flat | 50000 | Telat di atas 1 jam |

Lembur (BR-16) memakai tabel yang sama dengan `calc_type = hourly_multiplier`:
jam ke-1 → 1.5, jam ke-2 dst → 2.0.

#### `leave_types`, `salary_components`, `deduction_types`, `settings` (baru)

| Tabel | Kolom inti |
|---|---|
| `leave_types` | `code` UK, `name`, `is_paid`, `deducts_balance`, `requires_evidence`, `max_days_per_request`, `min_notice_days`, `default_entitlement_days`, `is_active`, `deleted_at` |
| `salary_components` | `code` UK, `name`, `category` (`earning`/`deduction`/`statutory`), `calc_type` (`fixed`/`per_day`/`per_hour`/`percent`), `is_taxable`, `is_active`, `sort_order`, `deleted_at` |
| `deduction_types` | `code` UK, `name`, `is_system` (dihitung sistem vs input manual), `default_amount`, `is_active`, `deleted_at` |
| `settings` | `branch_id`, `group`, `key`, `value` (json), `type`, `label`, `description` — `unique(branch_id, key)` |

Isi awal `settings`:

| key | value | group |
|---|---|---|
| `roster.min_rest_hours` | 10 | roster |
| `roster.max_consecutive_days` | 6 | roster |
| `roster.target_off_days_per_week` | 1 | roster |
| `roster.warn_double_shift` | true | roster |
| `attendance.check_in_out_strategy` | `earliest_latest` | attendance |
| `attendance.close_day_hour` | 6 | attendance |
| `overtime.min_minutes` | 60 | overtime |
| `overtime.allow_backdated` | true | overtime |
| `payroll.period_start_day` | 21 | payroll |
| `payroll.pay_day` | 21 | payroll |
| `payroll.working_days_basis` | `scheduled` | payroll |

---

### 2.3 Roster

#### `rosters` (baru)

| Kolom | Tipe | Null | Keterangan |
|---|---|---|---|
| id | bigint PK | | |
| branch_id | bigint FK | | |
| period_year | smallint UK | | |
| period_month | tinyint UK | | |
| status | string(16) IX | | `draft` / `published` / `locked` |
| published_at | timestamp | ✔ | |
| published_by | bigint FK users | ✔ | |
| note | text | ✔ | |
| timestamps | | | |

- `unique(branch_id, period_year, period_month)`

#### `roster_assignments` (baru — tabel terbesar kedua)

| Kolom | Tipe | Null | Keterangan |
|---|---|---|---|
| id | bigint PK | | |
| roster_id | bigint FK IX | | `cascadeOnDelete` |
| employee_id | bigint FK | | |
| work_date | date | | |
| shift_id | bigint FK | ✔ | `null` = tidak bertugas |
| shift_key | int | | `shift_id ?? 0` (§1.8) |
| division_id | bigint FK | ✔ | Bertugas sebagai apa hari itu |
| status | string(16) | | `scheduled` / `off` / `leave` / `holiday` / `cancelled` |
| source | string(16) | | `manual` / `generated` / `swap` / `leave` / `correction` |
| source_request_id | bigint FK requests | ✔ | Jejak balik ke pengajuan |
| note | string(255) | ✔ | |
| timestamps | | | |

- `unique(employee_id, work_date, shift_key)` ← inti D-04
- IX: `(work_date, shift_id, division_id)` — hitung pemenuhan kebutuhan shift
- IX: `(employee_id, work_date)` — jadwal saya
- IX: `(roster_id, status)`

Estimasi: 18 × 31 ≈ 560 baris/bulan, ±6.700/tahun.

---

### 2.4 Absensi

#### `device_callbacks` — **tidak berubah sama sekali**

#### `attendance_logs` ⟳

| Kolom | Keterangan |
|---|---|
| Semua kolom yang ada | **Tidak berubah**, termasuk `unique(cloud_id, pin, scan_minute)` |
| ⟳ employee_id | bigint FK, nullable. Hasil pencocokan lewat `employee_devices`. `null` = PIN tak dikenal |
| ⟳ resolved_at | timestamp, nullable. Kapan pencocokan terakhir dijalankan |
| ⟳ import_batch_id | bigint FK, nullable. Untuk `source = 'import'` |

- IX baru: `(employee_id, scanned_at)` — menggantikan `(pin, scanned_at)` sebagai
  jalur utama compute
- IX baru parsial secara logis: `employee_id IS NULL` → daftar anomali

Kolom `pin` **tetap tidak disentuh**. `employee_id` murni turunan yang boleh
dihitung ulang kapan saja.

#### `attendances` ⟳ — **perubahan paling berisiko, tabel sudah berisi data**

| Kolom | Tipe | Keterangan |
|---|---|---|
| id, employee_id, work_date, shift_id, scheduled_in, check_in_at, check_out_at, status, computed_at, timestamps | | Sudah ada |
| late_seconds | | Dipertahankan (presisi detik berguna), ditambah turunan menit |
| late_blocks | | ✖ **Dihapus** — konsep blok pindah ke `rule_tiers` |
| deduction_amount | | ✖ **Dihapus** — rupiah pindah ke `payslip_items` (D-18) |
| ⟳ shift_key | int | §1.8 |
| ⟳ roster_assignment_id | bigint FK | Nullable: ada absensi tanpa jadwal (masuk di hari libur) |
| ⟳ division_id | bigint FK | Snapshot |
| ⟳ scheduled_out | timestamp | Snapshot, untuk hitung pulang cepat |
| ⟳ late_minutes | int | `ceil(late_seconds/60)`, dipakai `rule_tiers` |
| ⟳ early_leave_seconds | int | |
| ⟳ early_leave_minutes | int | |
| ⟳ work_minutes | int | Durasi check_in → check_out |
| ⟳ overtime_minutes | int | **Hanya** dari `overtime_records` yang confirmed |
| ⟳ first_log_id / last_log_id | bigint FK | Jejak ke scan asal, untuk "klik sampai sumber" |
| ⟳ has_adjustment | boolean | Ada koreksi manusia yang diterapkan |
| ⟳ is_closed | boolean | Hari sudah difinalkan proses tutup hari |
| ⟳ closed_at | timestamp | |
| ⟳ source_note | string(255) | Ringkasan koreksi yang diterapkan |

- `unique(employee_id, work_date)` → **berubah** jadi
  `unique(employee_id, work_date, shift_key)`
- `status` nilai baru: `hadir` / `alpha` / `izin` / `sakit` / `cuti` / `libur`
  (nilai lama `telat` **dimigrasi** jadi `hadir` + `late_minutes > 0`)
- IX: `(work_date, status)` dipertahankan; tambah `(employee_id, work_date)`,
  `(is_closed, work_date)`

#### `attendance_adjustments` (baru, append-only)

| Kolom | Tipe | Null | Keterangan |
|---|---|---|---|
| id | bigint PK | | |
| employee_id | bigint FK | | Kunci **logis**, bukan `attendance_id` |
| work_date | date | | |
| shift_key | int | | |
| request_id | bigint FK | ✔ | Asal koreksi. Null = tindakan langsung manager |
| type | string(24) | | `set_check_in` / `set_check_out` / `set_status` / `waive_late` / `waive_early_leave` / `revert` |
| value_time | timestamp | ✔ | |
| value_status | string(16) | ✔ | |
| reason | text | | **Wajib** (BR-18) |
| evidence_path | string(255) | ✔ | |
| approved_by | bigint FK users | | |
| approved_at | timestamp | | |
| reverted_by_id | bigint FK self | ✔ | Baris yang membatalkan baris ini |
| created_at | timestamp | | Tanpa `updated_at` — **tidak pernah diubah** |

- IX: `(employee_id, work_date, shift_key)` — dibaca setiap compute
- Tidak ada `updated_at` dan tidak ada `deleted_at`. Membatalkan = menambah baris
  `revert`

#### `import_batches` (baru)

`id`, `branch_id`, `source`, `file_path`, `original_name`, `uploaded_by`,
`rows_total`, `rows_inserted`, `rows_duplicate`, `rows_failed`, `error_log` json,
`status`, `started_at`, `finished_at`, timestamps.

---

### 2.5 Pengajuan

#### `requests` (baru — induk)

| Kolom | Tipe | Null | Keterangan |
|---|---|---|---|
| id | bigint PK | | |
| code | string(24) UK | | `REQ-2026-08-0001` |
| branch_id | bigint FK | | |
| type | string(16) IX | | `leave` / `overtime` / `swap` / `correction` |
| employee_id | bigint FK IX | | Pengaju |
| status | string(24) IX | | `draft` / `pending_peer` / `pending_manager` / `approved` / `rejected` / `cancelled` / `expired` |
| submitted_at | timestamp | ✔ | |
| decided_by | bigint FK users | ✔ | |
| decided_at | timestamp | ✔ | |
| decision_note | text | ✔ | **Wajib** saat status `rejected` (service) |
| expires_at | timestamp | ✔ | Kedaluwarsa otomatis (I-15) |
| cancelled_at | timestamp | ✔ | |
| timestamps | | | |

- IX: `(status, type)` — inbox manager
- IX: `(employee_id, status)` — daftar pengajuan saya
- **Tidak ada** `deleted_at`. Membatalkan = status `cancelled`

#### Empat tabel detail

| Tabel | PK | Kolom |
|---|---|---|
| `leave_requests` | `request_id` PK+FK | `leave_type_id`, `start_date`, `end_date`, `total_days` decimal(4,1), `is_half_day`, `reason`, `handover_note` |
| `overtime_requests` | `request_id` PK+FK | `batch_id` uuid IX, `work_date`, `shift_id`, `planned_start` time, `planned_end` time, `planned_minutes`, `initiated_by` (`manager`/`employee`), `is_backdated`, `reason` |
| `shift_swap_requests` | `request_id` PK+FK | `requester_assignment_id` FK, `partner_employee_id` FK, `partner_assignment_id` FK, `partner_accepted_at`, `partner_rejected_at`, `partner_note`, `reason` |
| `attendance_corrections` | `request_id` PK+FK | `work_date`, `shift_key`, `correction_type` (`lupa_masuk`/`lupa_pulang`/`mesin_error`/`lainnya`), `proposed_check_in`, `proposed_check_out`, `proposed_status`, `reason` |

Semua `cascadeOnDelete` dari `requests` — detail tidak punya makna tanpa induk.

#### `request_attachments`

`id`, `request_id` FK IX, `path`, `original_name`, `mime_type`, `size_bytes`,
`uploaded_by` FK, timestamps.

Disk `local` (privat), **bukan** `public`. Bukti sakit adalah data medis — tidak
boleh bisa diakses lewat URL tebakan.

#### `leave_balances` / `leave_ledger`

| `leave_balances` | |
|---|---|
| `employee_id` UK, `leave_type_id` UK, `year` UK | `unique(employee_id, leave_type_id, year)` |
| `entitlement_days`, `carried_over_days`, `used_days`, `pending_days` | decimal(5,1) |
| `expires_at` | Batas pemakaian carry over |

| `leave_ledger` | |
|---|---|
| `leave_balance_id` FK IX, `request_id` FK, `delta_days` decimal(5,1) | Boleh negatif |
| `type` | `accrual` / `usage` / `reversal` / `carry_over` / `expiry` / `adjustment` |
| `note`, `created_by`, `created_at` | Tanpa `updated_at` — append-only |

#### `overtime_records`

| Kolom | Tipe | Keterangan |
|---|---|---|
| id, employee_id FK, work_date | | |
| overtime_request_id | FK, nullable | Null hanya untuk data historis |
| attendance_id | FK, nullable | Diisi ulang tiap compute |
| actual_start, actual_end | timestamp | Dari scan |
| actual_minutes, approved_minutes, payable_minutes | int | `payable = min(approved, actual)` — INV-04 |
| status | string(24) | `pending_confirmation` / `confirmed` / `rejected` |
| confirmed_by FK, confirmed_at, note | | |

- `unique(employee_id, work_date, overtime_request_id)`
- IX: `(status, work_date)`

---

### 2.6 Payroll

#### `payroll_periods`

| Kolom | Tipe | Keterangan |
|---|---|---|
| id, branch_id FK | | |
| code | string(16) UK | `2026-08` |
| start_date, end_date, pay_date | date | 21 Jul / 20 Agu / 21 Agu (D-01) |
| status | string(16) IX | `open` / `generating` / `generated` / `approved` / `locked` / `reopened` |
| approved_by, approved_at | | |
| locked_by, locked_at | | |
| reopened_by, reopened_at, reopen_reason | | `reason` wajib saat reopen |
| timestamps | | |

- `unique(branch_id, code)`
- **Tidak ada** `deleted_at`

#### `payroll_runs`

`id`, `payroll_period_id` FK IX, `version` (mulai 1), `status`
(`running`/`completed`/`failed`/`superseded`), `rule_snapshot` json (ID rule_set
yang dipakai), `employee_count`, `total_take_home_pay`, `generated_by` FK,
`started_at`, `finished_at`, `error_message`, timestamps.

- `unique(payroll_period_id, version)`
- Hanya satu run per periode yang boleh `completed`; sisanya `superseded`

#### `payslips`

| Kolom | Tipe | Keterangan |
|---|---|---|
| id, payroll_run_id FK IX, employee_id FK | | |
| code | string(24) UK | `SLIP-2026-08-014` |
| employee_snapshot | json | Nama, divisi, PIN, nomor induk **saat itu** |
| total_earning, total_deduction, total_statutory, take_home_pay | bigint | |
| scheduled_days, present_days, absent_days, leave_days, late_count, early_leave_count | int | Ringkasan absensi |
| overtime_minutes | int | |
| status | string(16) | `draft` / `published` |
| published_at, pdf_path, pdf_generated_at | | |
| timestamps | | |

- `unique(payroll_run_id, employee_id)`
- **INV-10**: baris `published` tidak boleh berubah nilainya

#### `payslip_items`

| Kolom | Tipe | Keterangan |
|---|---|---|
| id, payslip_id FK IX | | `cascadeOnDelete` |
| salary_component_id | FK, nullable | |
| category | string(16) | `earning` / `deduction` / `statutory` / `info` |
| label | string(100) | **Disalin**, bukan dirujuk |
| qty | decimal(8,2) | 3 hari, 2,5 jam |
| rate | bigint | |
| amount | bigint | Boleh negatif untuk penyesuaian |
| source_type / source_id | string / bigint | Polymorphic ke `attendances`, `overtime_records`, `cash_advance_installments`, `manual_payroll_entries`, `payroll_adjustments` |
| rule_snapshot | json | Salinan tier yang dipakai |
| sort_order, note | | |

- IX: `(payslip_id, category, sort_order)`
- IX: `(source_type, source_id)` — telusur balik

#### Sisanya

| Tabel | Kolom inti |
|---|---|
| `employee_salaries` | `employee_id` FK, `salary_component_id` FK, `amount` bigint, `effective_from` date, `effective_to` date null, `note`, `created_by` — IX `(employee_id, effective_from)` |
| `manual_payroll_entries` | `employee_id`, `payroll_period_id`, `entry_type` (`bonus`/`deduction`), `deduction_type_id` null, `amount` bigint, `reason` **NOT NULL** (BR-23), `created_by`, `payslip_item_id` null — IX `(payroll_period_id, employee_id)` |
| `cash_advances` | `employee_id`, `amount`, `installments_count`, `reason`, `status` (`pending`/`approved`/`disbursed`/`paid_off`/`cancelled`), `approved_by`, `disbursed_at` |
| `cash_advance_installments` | `cash_advance_id` FK, `payroll_period_id` FK null, `sequence`, `amount`, `status` (`scheduled`/`deducted`/`skipped`/`written_off`), `payslip_item_id` null — `unique(cash_advance_id, sequence)` |
| `payroll_adjustments` | `employee_id`, `origin_period_id` FK, `applied_period_id` FK, `amount` bigint (boleh negatif), `reason` NOT NULL, `source_type`/`source_id`, `created_by`, `approved_by` — I-12 |

---

### 2.7 Sistem

#### `audit_logs`

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| actor_type | string(16) IX | `user` / `system` |
| actor_id | bigint FK null | Null saat sistem |
| actor_name | string(100) | Disalin — akun bisa dihapus, jejak tidak boleh putus |
| action | string(64) IX | `roster.published`, `payroll.locked`, `auth.login` |
| auditable_type / auditable_id | string / bigint | Polymorphic |
| old_values / new_values | json | |
| ip | string(45) | |
| user_agent | string(255) | |
| context | json | Info tambahan (alasan, request_id) |
| created_at | timestamp IX | Tanpa `updated_at` |

- IX: `(auditable_type, auditable_id)`, `(actor_type, created_at)`
- Tabel dengan pertumbuhan paling cepat. Rencana arsip: pindahkan baris
  `actor_type = 'system'` yang lebih tua dari 1 tahun

#### Notifikasi

| Tabel | Kolom inti |
|---|---|
| `notification_templates` | `code` UK, `channel`, `subject`, `body_template`, `variables` json, `is_active` |
| `notifications` | `user_id` FK IX, `template_code`, `title`, `body`, `payload` json, `link`, `read_at` — IX `(user_id, read_at)` |
| `notification_deliveries` | `notification_id` FK IX, `channel`, `status`, `attempts`, `sent_at`, `failed_at`, `error` |

---

## 3. Rencana migrasi

Urutan wajib dipatuhi karena ketergantungan foreign key. Setiap batch adalah satu
titik yang bisa dihentikan dengan aman.

> **Sebelum setiap batch:** salin file `database/database.sqlite`. Ini backup
> paling murah yang pernah ada — manfaatkan.

### Batch 1 — Fondasi (tanpa risiko, semuanya tabel baru)

```
2026_08_07_100000_create_branches_table
2026_08_07_100100_create_divisions_table
2026_08_07_100200_create_settings_table
2026_08_07_100300_create_leave_types_table
2026_08_07_100400_create_salary_components_table
2026_08_07_100500_create_deduction_types_table
2026_08_07_100600_create_rule_sets_table
2026_08_07_100700_create_rule_tiers_table
```

Seeder mengisi: 1 branch, 5 divisi, ±11 setting, 4 leave type, 3 salary
component, 5 deduction type, dan rule_set awal yang **nilainya diambil dari
`config/attendance.php` yang sekarang** — supaya perilaku tidak berubah sedikit
pun saat batch ini masuk.

### Batch 2 — Perluasan master yang sudah ada

```
2026_08_07_110000_extend_shifts_table
2026_08_07_110100_extend_employees_table
2026_08_07_110200_create_employee_devices_table
2026_08_07_110300_create_employee_divisions_table
2026_08_07_110400_extend_users_table
2026_08_07_110500_extend_holidays_table
2026_08_07_110600_backfill_employee_data          ← migration berisi data
```

`backfill_employee_data` melakukan:

1. `employees.branch_id = 1` untuk semua baris
2. `employee_no` digenerate (`EMP-001` dst) kalau kafe belum punya penomoran
3. Setiap `employees.pin_device` → satu baris `employee_devices` dengan
   `valid_from = joined_at ?? '2020-01-01'`, `valid_to = null`
4. `employment_status` diturunkan dari `is_active`
5. `shift_id` → `default_shift_id`, `off_days` → `preferred_off_days` (rename,
   nilai dipertahankan)
6. Setiap karyawan diberi satu `employee_divisions` dengan `is_primary = true` —
   **butuh input manual**, karena data divisi belum pernah ada. Sementara semua
   diarahkan ke divisi `waiter` dan ditandai untuk dikoreksi manager

Poin 6 adalah satu-satunya tempat di seluruh migrasi yang butuh keputusan
manusia. Saya buatkan command `php artisan employee:assign-division` yang
interaktif untuk 18 orang — lebih cepat daripada lewat UI yang belum ada.

### Batch 3 — Roster

```
2026_08_07_120000_create_rosters_table
2026_08_07_120100_create_roster_assignments_table
2026_08_07_120200_create_staffing_requirements_table
```

Seeder `staffing_requirements` mengisi BR-11 & BR-12. Cleaning Service sementara
1 pagi / 1 malam (menunggu konfirmasi).

Roster bulan berjalan **tidak** dibuat otomatis — dibuat manager lewat UI di
Tahap 13. Sampai itu ada, `AttendanceComputer` mundur ke `default_shift_id` bila
tidak menemukan assignment (jalur kompatibilitas, dihapus setelah roster dipakai).

### Batch 4 — Absensi (paling sensitif)

```
2026_08_07_130000_extend_attendance_logs_table
2026_08_07_130100_create_import_batches_table
2026_08_07_130200_restructure_attendances_table
2026_08_07_130300_create_attendance_adjustments_table
2026_08_07_130400_backfill_attendance_data
```

`restructure_attendances_table` — ini yang harus hati-hati di SQLite:

1. Tambah kolom baru (`shift_key`, `late_minutes`, `early_leave_*`,
   `work_minutes`, `overtime_minutes`, dst) — semuanya nullable/berdefault
2. Isi `shift_key = shift_id ?? 0` untuk baris lama
3. **Baru** hapus unique lama, buat unique baru
4. Terakhir hapus `late_blocks` dan `deduction_amount`

Urutan ini penting: menghapus kolom di SQLite memicu pembuatan ulang tabel oleh
Laravel. Melakukannya **setelah** unique key yang baru sudah benar membuat
pembuatan ulang itu membawa struktur final, bukan struktur setengah jadi.

`backfill_attendance_data`:
- `status = 'telat'` → `status = 'hadir'` (nilai `late_seconds` sudah ada, jadi
  informasi "telat" tidak hilang — hanya berpindah dimensi)
- `late_minutes = ceil(late_seconds / 60)`
- `attendance_logs.employee_id` diisi lewat `employee_devices`

Data `deduction_amount` lama **tidak dipindahkan ke mana-mana**. Nilainya adalah
hasil aturan flat yang tidak pernah dipakai membayar gaji (modul payroll belum
ada), jadi tidak ada yang hilang. Kalau ternyata pernah dipakai sebagai acuan
manual, sebaiknya diekspor ke CSV dulu sebelum batch ini — bilang saja.

### Batch 5 — Pengajuan

```
2026_08_07_140000_create_requests_table
2026_08_07_140100_create_leave_requests_table
2026_08_07_140200_create_overtime_requests_table
2026_08_07_140300_create_shift_swap_requests_table
2026_08_07_140400_create_attendance_corrections_table
2026_08_07_140500_create_request_attachments_table
2026_08_07_140600_create_leave_balances_table
2026_08_07_140700_create_leave_ledger_table
2026_08_07_140800_create_overtime_records_table
2026_08_07_140900_add_source_request_fk_to_roster_assignments
```

Migration terakhir menambahkan FK `roster_assignments.source_request_id` →
`requests.id`, yang sengaja ditunda karena `requests` belum ada saat Batch 3.

### Batch 6 — Payroll & Sistem

```
2026_08_07_150000_create_payroll_periods_table
2026_08_07_150100_create_payroll_runs_table
2026_08_07_150200_create_payslips_table
2026_08_07_150300_create_payslip_items_table
2026_08_07_150400_create_employee_salaries_table
2026_08_07_150500_create_manual_payroll_entries_table
2026_08_07_150600_create_cash_advances_table
2026_08_07_150700_create_cash_advance_installments_table
2026_08_07_150800_create_payroll_adjustments_table
2026_08_07_150900_backfill_employee_salaries      ← pindahkan base_salary
2026_08_07_151000_drop_base_salary_from_employees
2026_08_07_151100_create_audit_logs_table
2026_08_07_151200_create_notification_templates_table
2026_08_07_151300_create_notifications_table
2026_08_07_151400_create_notification_deliveries_table
```

`backfill_employee_salaries`: setiap `employees.base_salary` → satu baris
`employee_salaries` dengan komponen `gaji_pokok`, `effective_from = joined_at ??
'2020-01-01'`. Kolom lama baru dihapus di migration berikutnya, **bukan di
migration yang sama** — supaya kalau backfill gagal, datanya masih ada.

### Ringkasan risiko

| Batch | Risiko | Bisa dibalik? |
|---|---|---|
| 1 | Nihil | Ya |
| 2 | Rendah — rename kolom | Ya |
| 3 | Nihil | Ya |
| 4 | **Tinggi** — tabel berisi data dibuat ulang | Ya, dari backup file |
| 5 | Nihil | Ya |
| 6 | Rendah | Ya |

---

## 4. Konfigurasi SQLite — sudah benar, satu hal yang perlu ditambah

Saya sempat menyiapkan daftar pragma yang "wajib ditambahkan". Ternyata
`config/database.php` sudah mengaturnya semua, lengkap dengan alasannya di
komentar. Diverifikasi langsung di database yang sedang berjalan:

```
PRAGMA foreign_keys  →  1
PRAGMA journal_mode  →  wal
```

| Pragma | Nilai terpasang | Kenapa penting untuk rancangan ini |
|---|---|---|
| `foreign_key_constraints` | `true` | Tanpa ini seluruh FK di Tahap 3 cuma dekorasi. SQLite mematikannya secara default — di sini sudah dihidupkan |
| `journal_mode` | `WAL` | Dashboard tetap bisa dibuka saat payroll digenerate |
| `busy_timeout` | `5000` | Tabrakan webhook × cron menunggu 5 detik, bukan langsung gagal |
| `synchronous` | `NORMAL` | Aman dipadu WAL, jauh lebih cepat dari `FULL` |

**Yang perlu ditinjau: `transaction_mode` = `DEFERRED`.**

Transaksi DEFERRED belum mengambil kunci tulis saat `BEGIN` — kuncinya baru
diambil saat penulisan pertama. Untuk transaksi yang **membaca banyak lalu
menulis** (persis bentuk generate payroll: baca absensi sebulan, baca rule, baca
lembur, baru tulis slip), ini bisa berakhir gagal saat proses lain menulis di
antara baca dan tulis — dan kegagalan jenis ini tidak selalu bisa diselamatkan
`busy_timeout`.

Untuk pipeline absensi yang ada sekarang, DEFERRED sudah tepat: insert-nya cepat
dan tidak didahului baca panjang. Jadi jangan diubah global. Yang saya usulkan:
**generate payroll membuka transaksinya sendiri dalam mode IMMEDIATE**, sehingga
kunci tulis diambil di awal dan proses lain menunggu dengan tertib. Detailnya
masuk di Tahap 12 saat `PayrollGenerator` ditulis.

---

## 5. Estimasi ukuran (5 tahun, 18 karyawan)

| Tabel | Baris/tahun | 5 tahun |
|---|---|---|
| `attendance_logs` | ±13.000 | 65.000 |
| `device_callbacks` | ±13.000 | 65.000 |
| `roster_assignments` | ±6.700 | 33.500 |
| `attendances` | ±6.700 | 33.500 |
| `audit_logs` | ±50.000 | 250.000 |
| `payslip_items` | ±2.200 | 11.000 |
| Sisanya | < 2.000 | < 10.000 |
| **Total** | | **< 500.000 baris, < 300 MB** |

Jauh di bawah batas nyaman SQLite. Tidak ada satu pun tabel yang butuh
partisi, sharding, atau optimasi khusus. **Jangan mengoptimasi apa pun sebelum
ada ukuran yang menunjukkan masalah.**

---

## 6. Checklist konsistensi

| Cek | Status |
|---|---|
| Setiap entitas ERD punya spesifikasi kolom | ✅ 44/44 |
| Setiap FK punya indeks | ✅ |
| Setiap tabel append-only tanpa `updated_at`/`deleted_at` | ✅ `attendance_adjustments`, `leave_ledger`, `audit_logs` |
| Tidak ada rupiah di modul absensi | ✅ 2 kolom dihapus |
| Semua kolom uang bertipe integer | ✅ |
| Setiap tabel transaksi punya `status`, bukan `deleted_at` | ✅ |
| Setiap perubahan pada tabel berisi data punya rencana backfill | ✅ Batch 2, 4, 6 |
| Migrasi bisa dibalik | ✅ Semua `down()` ditulis |
| Tidak memakai fitur khusus satu engine | ✅ Portabel SQLite / MySQL / PostgreSQL |

---

## 7. Yang perlu Anda putuskan sebelum Tahap 5

Tidak memblokir — semua punya default yang aman:

1. **Divisi tiap karyawan** (Batch 2 poin 6). Saya siapkan command interaktif;
   butuh 5 menit untuk 18 orang. Alternatif: kirim daftar nama + divisinya, saya
   masukkan ke seeder.
2. **Nomor induk karyawan** — generate `EMP-001` atau pakai penomoran kafe?
3. **Data `deduction_amount` lama** — perlu diekspor dulu, atau boleh dibuang?
4. **Kebutuhan Cleaning Service** per shift.
