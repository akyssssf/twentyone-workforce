# Tahap 10 & 11 — Spesifikasi REST API dan Struktur Project

Cafe Workforce Management System

---

# TAHAP 10 — Spesifikasi REST API

## 10.1 Status saat ini

Aplikasi web berjalan dengan **session + Blade**, bukan SPA. Yang sudah ada di
`routes/api.php` hanya satu endpoint: webhook Fingerspot.

REST API di bawah ini adalah **rancangan untuk aplikasi mobile karyawan** —
belum diimplementasi, dan sengaja begitu. Menulis API sekarang berarti memelihara
dua pintu masuk untuk fitur yang sama sebelum ada yang memakainya. Yang penting
sudah dikerjakan: seluruh logika bisnis ada di service layer, bukan di
controller, jadi menambahkan API nanti tinggal membuat controller tipis yang
memanggil service yang sama.

## 10.2 Konvensi

| Aspek | Keputusan |
|---|---|
| Base URL | `/api/v1` |
| Autentikasi | Bearer token (Sanctum) |
| Format | JSON, `snake_case` |
| Tanggal | ISO 8601 dengan offset: `2026-08-06T09:00:00+07:00` |
| Uang | Integer rupiah, tanpa desimal |
| Paginasi | `?page=`, `?per_page=` (maks 100) |
| Error | `{ "message": "...", "errors": { "field": ["..."] } }` |
| Versi | Di URL, bukan di header — lebih mudah dibaca di log |

## 10.3 Endpoint karyawan (prioritas mobile)

### Autentikasi

| Method | Endpoint | Keterangan |
|---|---|---|
| POST | `/api/v1/auth/login` | `{email, password, device_name}` → token |
| POST | `/api/v1/auth/logout` | Cabut token yang dipakai |
| GET | `/api/v1/me` | Profil + divisi + saldo cuti |
| PUT | `/api/v1/me/password` | Ganti kata sandi |

### Jadwal & absensi

| Method | Endpoint | Keterangan |
|---|---|---|
| GET | `/api/v1/me/roster?month=2026-08` | Jadwal saya sebulan. Hanya roster yang sudah terbit |
| GET | `/api/v1/me/attendances?month=2026-08` | Riwayat absensi + ringkasan |
| GET | `/api/v1/me/attendances/{date}` | Satu hari, termasuk jejak koreksi |

```jsonc
// GET /api/v1/me/attendances/2026-08-06
{
  "data": {
    "work_date": "2026-08-06",
    "shift": { "code": "pagi", "name": "Shift Pagi", "start": "09:00", "end": "17:00" },
    "division": "Barista",
    "scheduled_in": "2026-08-06T09:00:00+07:00",
    "check_in_at": "2026-08-06T09:03:12+07:00",
    "check_out_at": "2026-08-06T17:02:41+07:00",
    "late_minutes": 4,          // > 0 berarti "Terlambat"
    "early_leave_minutes": 0,   // > 0 berarti "Pulang Cepat"
    "work_minutes": 479,
    "overtime_minutes": 0,      // hanya dari lembur yang disetujui
    "status": "hadir",
    "has_adjustment": false
  }
}
```

Perhatikan: tidak ada satu pun kolom rupiah di respons absensi. Ini bukan
kelalaian — batas "absensi mencatat fakta, payroll menghitung uang" ikut berlaku
di API.

### Pengajuan

| Method | Endpoint | Keterangan |
|---|---|---|
| GET | `/api/v1/me/requests?status=pending` | Pengajuan saya |
| POST | `/api/v1/me/requests/leave` | Ajukan cuti/izin/sakit |
| POST | `/api/v1/me/requests/overtime` | Ajukan lembur |
| POST | `/api/v1/me/requests/swap` | Ajukan tukar shift |
| POST | `/api/v1/me/requests/correction` | Ajukan koreksi absensi |
| POST | `/api/v1/me/requests/{id}/attachments` | Unggah bukti (multipart) |
| POST | `/api/v1/me/requests/{id}/cancel` | Batalkan |
| POST | `/api/v1/me/requests/{id}/respond` | Jawab tukar shift sebagai rekan |
| GET | `/api/v1/me/swap-candidates?assignment_id=` | Rekan yang kompeten & tidak bentrok |

```jsonc
// POST /api/v1/me/requests/leave
{ "leave_type_id": 1, "start_date": "2026-08-16", "end_date": "2026-08-17",
  "reason": "Menghadiri pernikahan saudara" }

// 201
{ "data": { "id": 12, "code": "REQ-2026-08-0001", "status": "pending_manager" } }

// 422 — saldo tidak cukup
{ "message": "Sisa Cuti Tahunan tinggal 1 hari, tidak cukup untuk 2 hari." }
```

### Slip gaji

| Method | Endpoint | Keterangan |
|---|---|---|
| GET | `/api/v1/me/payslips` | Hanya slip berstatus terbit |
| GET | `/api/v1/me/payslips/{id}` | Rincian per baris |
| GET | `/api/v1/me/payslips/{id}/pdf` | Unduh |

## 10.4 Endpoint manajer

| Method | Endpoint | Keterangan |
|---|---|---|
| GET | `/api/v1/dashboard?date=` | Sembilan metrik + roster hari itu |
| GET | `/api/v1/rosters` · `POST` | Daftar & buat periode |
| GET | `/api/v1/rosters/{id}` | Grid + hasil validasi |
| POST | `/api/v1/rosters/{id}/generate` | Isi otomatis |
| PUT | `/api/v1/rosters/{id}/assignments` | Ubah satu/banyak sel |
| POST | `/api/v1/rosters/{id}/publish` | Terbitkan |
| GET | `/api/v1/requests?status=pending_manager` | Inbox |
| POST | `/api/v1/requests/{id}/approve` · `/reject` | Putuskan |
| GET | `/api/v1/overtime/records?status=pending_confirmation` | Realisasi menunggu |
| POST | `/api/v1/overtime/records/{id}/confirm` | Sahkan realisasi |
| GET | `/api/v1/payroll/periods` · `POST` | Periode |
| POST | `/api/v1/payroll/periods/{id}/generate` · `/approve` · `/lock` · `/reopen` | Siklus payroll |
| GET | `/api/v1/employees` · `POST` · `PUT` | Master karyawan |
| GET | `/api/v1/attendance/anomalies` | PIN tak dikenal, scan tanpa pasangan |
| GET | `/api/v1/audit-logs` | Jejak |

## 10.5 Kode status yang dipakai

| Kode | Dipakai untuk |
|---|---|
| 200 / 201 | Berhasil |
| 401 | Token tidak ada / kedaluwarsa |
| 403 | Peran salah, **atau membuka data milik orang lain** |
| 404 | Tidak ada |
| 409 | Bentrok keadaan: periode terkunci, pengajuan sudah diputuskan |
| 422 | Validasi gagal, saldo tidak cukup, lembur di bawah minimum |
| 429 | Terlalu banyak permintaan |

**409 vs 422** dibedakan dengan sengaja. 422 berarti "isian Anda salah,
perbaiki". 409 berarti "isian Anda benar, tapi keadaan sistem menolak" — periode
sudah dikunci, atau pengajuan sudah keburu diputuskan orang lain. Aplikasi
mobile perlu memperlakukan keduanya berbeda: yang satu minta perbaiki formulir,
yang satu minta muat ulang halaman.

## 10.6 Yang TIDAK akan pernah ada di API

Daftar ini sama pentingnya dengan daftar endpoint:

- Endpoint apa pun yang membaca `attendance_logs` untuk keperluan payroll.
- Endpoint yang mengubah `attendances` secara langsung — koreksi selalu lewat
  pengajuan, supaya ada alasan, ada persetujuan, dan ada jejak.
- Endpoint yang menghapus `audit_logs`, `payslips`, atau `attendance_adjustments`.
- Endpoint absensi yang mengembalikan rupiah.

---

# TAHAP 11 — Struktur Project

## 11.1 Struktur nyata (yang sudah berjalan)

```
app/
├── Console/Commands/          # 15 perintah CLI
│   ├── AddEmployee.php  EditEmployee.php  ImportEmployees.php
│   ├── ComputeAttendances.php  SyncAttlog.php  ParseDeviceCallbacks.php
│   └── ManageHolidays.php  CheckFingerspot.php  ...
│
├── Enums/                     # Nilai domain, bukan konstanta liar
│   ├── AttendanceStatus.php   # 6 status, bukan 9 — lihat Tahap 2
│   ├── RequestType.php  RequestStatus.php
│   ├── RosterStatus.php  AssignmentStatus.php
│   ├── PayrollStatus.php  RuleType.php  UserRole.php
│
├── Http/
│   ├── Controllers/
│   │   ├── DashboardController.php  ReportController.php
│   │   ├── ScanActivityController.php
│   │   ├── Auth/LoginController.php
│   │   ├── FingerspotWebhookController.php
│   │   ├── Manajer/           # ← area manajer
│   │   │   ├── RosterController.php  RequestApprovalController.php
│   │   │   ├── PayrollController.php  EmployeeController.php
│   │   │   └── RuleController.php  AuditController.php
│   │   └── Karyawan/          # ← area karyawan
│   │       ├── EmployeePortalController.php
│   │       ├── EmployeeRequestController.php
│   │       └── PayslipController.php
│   └── Middleware/
│       ├── EnsureUserIsActive.php      EnsureUserIsOwner.php
│       ├── EnsureUserIsManagement.php  EnsureUserIsEmployee.php
│       └── VerifyFingerspotWebhook.php
│
├── Models/                    # 43 model + Concerns/HasShiftKey.php
│
├── Services/                  # ← seluruh logika bisnis ada di sini
│   ├── Attendance/
│   │   ├── AttendanceComputer.php   # scan → Final Attendance
│   │   ├── WorkWindow.php           # jendela kerja lintas tengah malam
│   │   ├── OvertimeResolver.php     # lembur hanya dari approval
│   │   ├── MonthlyReport.php  MonthlyReportExcel.php
│   ├── Fingerspot/                  # ← TIDAK DISENTUH sama sekali
│   │   ├── FingerspotClient.php  AttlogParser.php
│   │   ├── AttlogSynchronizer.php  ScanData.php
│   ├── Roster/
│   │   ├── RosterService.php        # buat, isi, ubah, terbitkan
│   │   └── RosterValidator.php      # error / warning / info
│   ├── Requests/
│   │   ├── RequestService.php       # satu mesin state, empat jenis
│   │   └── LeaveService.php         # saldo & ledger cuti
│   ├── Payroll/
│   │   ├── PayrollGenerator.php     # satu-satunya tempat rupiah dihitung
│   │   ├── PayrollPeriodFactory.php # periode, approve, lock, reopen
│   │   └── PayrollLockGuard.php     # gerbang tunggal aturan terkunci
│   ├── Rules/RuleResolver.php       # aturan yang berlaku pada tanggal X
│   ├── Audit/AuditLogger.php
│   └── Notifications/Notifier.php
│
└── Support/                   # Utilitas murni, tanpa state
    ├── Settings.php  DateInput.php  DayOfWeek.php  PhoneNumber.php

database/
├── migrations/                # 11 lama + 7 baru (per batch)
├── seeders/
│   ├── MasterDataSeeder.php   # wajib: divisi, shift, aturan, setelan
│   └── DemoSeeder.php         # 18 karyawan contoh, non-produksi saja
└── factories/

resources/views/
├── layouts/app.blade.php      # navigasi berbeda per peran
├── components/                # kartu, status-badge
├── dashboard/  laporan/  aktivitas/  auth/
├── manajer/{roster,pengajuan,lembur,payroll,karyawan,aturan,audit}/
├── karyawan/{pengajuan,slip}/ + beranda, jadwal, absensi
└── slip/show.blade.php        # dipakai bersama manajer & karyawan

docs/                          # 00–11, dokumen desain per tahap
```

## 11.2 Aturan arsitektur yang ditegakkan

**Controller tipis, service tebal.** Controller hanya boleh: validasi input,
panggil satu service, kembalikan respons. Tidak ada perhitungan di controller.
Ini yang membuat REST API nanti bisa memakai ulang seluruh logika tanpa
menyalinnya.

**Arah ketergantungan satu arah.**

```
Http  →  Services  →  Models
                  ↘  Support
```

Model tidak pernah memanggil service. Service tidak pernah menyentuh `Request`
HTTP. `Support` tidak bergantung pada apa pun.

**Modul Payroll tidak punya jalan ke `attendance_logs`.** Ini larangan BR-02
yang ditegakkan secara struktural — dan bisa dijaga otomatis dengan satu test
arsitektur:

```php
// Payroll dilarang menyentuh scan mentah.
$file = file_get_contents(app_path('Services/Payroll/PayrollGenerator.php'));
$this->assertStringNotContainsString('AttendanceLog', $file);
```

**Yang tidak dipakai, dan alasannya.**

| Pola | Dipakai? | Alasan |
|---|---|---|
| Service Layer | ✅ | Wajib supaya API/mobile bisa memakai ulang |
| Repository Pattern | ❌ | Eloquent sudah jadi repository. Lapisan tambahan di skala ini cuma menambah file tanpa menambah kemampuan — dan menyulitkan `whereHas` yang sering dipakai |
| Action/Command class per operasi | ❌ | Untuk 6 modul, service per modul lebih mudah dibaca daripada 60 kelas action |
| DTO | Sebagian | Dipakai di `ScanData` (batas sistem luar). Di dalam aplikasi, array asosiatif cukup |
| Event/Listener | Sebagian | Dipakai model event untuk `shift_key` dan pencocokan PIN |
| Queue | Belum | Payroll 18 karyawan selesai dalam hitungan detik. Dipindahkan ke queue saat karyawan > 100 |

Repository Pattern disebut di brief sebagai prinsip. Saya tidak memakainya, dan
itu keputusan sadar: pada 44 tabel dengan satu sumber data, repository hanya
menyalin API Eloquent ke antarmuka baru yang harus ikut diubah setiap kali
kebutuhan query berubah. Kalau nanti ada kebutuhan nyata — misalnya sebagian
data pindah ke layanan lain — lapisan itu bisa ditambahkan tepat di tempat yang
membutuhkannya, bukan di 43 model sekaligus.

## 11.3 Perintah CLI yang tersedia

```bash
php artisan employee:add        # tambah karyawan (interaktif / opsi)
php artisan employee:edit       # ubah data, gaji, shift preferensi, libur
php artisan employee:list       # daftar + peringatan yang belum lengkap
php artisan employee:import     # impor CSV
php artisan employee:toggle     # aktifkan / nonaktifkan
php artisan attendance:compute  # hitung ulang rentang tanggal
php artisan attendance:status   # ringkasan kondisi data absensi
php artisan attendance:parse-callbacks
php artisan attendance:sync-attlog
php artisan holiday             # kelola tanggal merah
php artisan fingerspot:check    # cek koneksi mesin
php artisan user:add            # akun dashboard
```
