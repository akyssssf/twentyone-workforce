# Dokumen Desain — Cafe Workforce Management System

Urutan baca. Setiap tahap membangun di atas tahap sebelumnya.

| Tahap | Dokumen | Isi |
|---|---|---|
| — | [00 — Log Keputusan](00-keputusan.md) | Semua keputusan yang mengunci bentuk sistem, beserta alasannya |
| 1 | [01 — Analisis Kebutuhan Bisnis](01-analisis-kebutuhan-bisnis.md) | Aktor, 30 aturan bisnis, 14 gap, 12 konflik, kalkulasi kapasitas SDM |
| 2 | [02 — Usulan Perbaikan](02-usulan-perbaikan.md) | 26 usulan dengan trade-off masing-masing |
| 3 | [03 — ERD](03-erd.md) | 44 entitas dalam 7 modul + keterlacakan ke setiap aturan bisnis |
| 4 | [04 — Struktur Database](04-struktur-database.md) | Kolom, indeks, constraint, rencana migrasi 6 batch |
| 5–6 | [05 — Relasi & Flowchart](05-relasi-dan-flowchart.md) | Matriks relasi, cascade, dan alur seluruh proses |
| 7–9 | [07 — Sitemap, Halaman, Wireframe](07-sitemap-halaman-wireframe.md) | 24 halaman beserta rancangan tampilannya |
| 10–11 | [10 — REST API & Struktur Project](10-rest-api-dan-struktur-folder.md) | Rancangan API mobile + struktur folder & aturan arsitektur |
| 12 | [12 — Deploy ke Server (VPS)](12-deploy.md) | Langkah pasang lengkap: nginx, HTTPS, cron, antrean, backup |
| 13 | [13 — **Deploy di Hostinger**](13-hostinger.md) | Jalur paling simpel, tanpa urus server. **Mulai dari sini kalau baru pertama kali deploy.** |

---

## Enam keputusan yang paling menentukan

Kalau hanya sempat membaca satu halaman, baca yang ini.

**1. Absensi mencatat FAKTA, payroll menghitung UANG.**
Tidak ada satu pun kolom rupiah di modul absensi. Ini yang membuat payroll lock
benar-benar berlaku dan membuat recompute absensi aman dijalankan kapan saja.
Dijaga otomatis oleh `ArchitectureTest`.

**2. "9 status absensi" sebenarnya 3 dimensi.**
Orang bisa Hadir + Terlambat + Pulang Cepat + Lembur di hari yang sama. Status
tersimpan hanya 6; Terlambat dan Pulang Cepat adalah angka menit, Lembur adalah
entitas dengan approval sendiri. Dashboard tetap menampilkan 9 angka.

**3. Koreksi manusia adalah INPUT, bukan output.**
`attendance_adjustments` bersifat append-only dan diterapkan ulang di akhir
setiap perhitungan. Tabel `attendances` tetap boleh dihapus dan dibangun ulang
kapan saja tanpa menghilangkan satu pun keputusan manager.

**4. Periode payroll 21–20, bukan bulan kalender.**
Data absensi sudah lengkap saat payroll dihitung. Konsekuensinya laporan absensi
(per bulan) dan laporan gaji (per periode) memang tidak akan pernah cocok
angkanya — dan itu disebut eksplisit di UI.

**5. Pemetaan PIN mesin berperiode.**
Tanpa ini, PIN yang dipakai ulang karyawan baru akan menarik seluruh riwayat
absensi karyawan lama ikut berpindah — diam-diam, tanpa error, dan baru
ketahuan saat gajian.

**6. Validasi roster memperingatkan, tidak memblokir.**
Dengan 18 karyawan sementara kebutuhan 21–22, roster yang memenuhi semua
kebutuhan minimum sekaligus memberi libur mingguan secara matematis mustahil.
Sistem yang memaksakan aturan yang tidak bisa dipenuhi akan ditinggalkan
penggunanya.

---

## Temuan yang perlu keputusan manajemen

**Kekurangan tenaga 3–4 orang.** Kebutuhan 15 orang/hari di luar Cleaning
Service × 7 hari = 105 man-day/minggu. Dengan libur 1 hari/minggu, dibutuhkan
19 orang inti + 2–3 cleaning = 21–22 orang. Saat ini 18. Perhitungan lengkap
per divisi ada di [Tahap 1 bagian D](01-analisis-kebutuhan-bisnis.md).

Sistem tidak menutupi kekurangan ini — ia menampilkannya sebagai peringatan di
setiap roster, supaya keputusan rekrutmen punya dasar angka.

---

## Yang masih menunggu konfirmasi

1. **Tipe mesin** — brief menyebut Revo W-231N, komentar di kode menyebut
   Vivo W-2421M. Kalau benar Revo (tanpa kamera), `photo_url` selalu kosong dan
   modul koreksi absensi jadi satu-satunya jalan menyelesaikan sengketa.
2. **Kebutuhan Cleaning Service per shift** — sementara diisi 1 pagi / 1 malam.
3. **Nomor induk karyawan** — sementara digenerate `EMP-001`.
