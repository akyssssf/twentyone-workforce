# Log Keputusan Desain

Cafe Workforce Management System
Dokumen hidup — setiap keputusan yang mengunci bentuk sistem dicatat di sini
supaya alasannya tidak hilang saat orang lain (atau kita sendiri 6 bulan lagi)
bertanya "kenapa dulu begini".

Format: **keputusan → alasan → konsekuensi**.

---

## Keputusan bisnis (dikonfirmasi 6 Agustus 2026)

### D-01 · Periode payroll = tanggal 21 s/d 20

Slip gaji 21 Agustus menghitung kerja **21 Juli – 20 Agustus**.

**Alasan.** Data absensi sudah lengkap saat payroll digenerate. Tidak ada hari
yang harus ditebak atau dibayar di muka.

**Konsekuensi.**
- `payroll_periods` adalah entitas tersendiri dengan `start_date`/`end_date`,
  **bukan** bulan kalender. Semua penguncian menempel ke periode ini.
- Roster tetap disusun per bulan kalender (1–31) karena manager berpikir begitu.
  Dua siklus ini sengaja tidak disamakan.
- Laporan absensi bulanan dan laporan gaji **tidak akan pernah cocok angkanya**.
  UI wajib menyebut rentang tanggal di setiap header laporan, jangan hanya
  menulis "Agustus 2026".
- Menyelesaikan G-01.

### D-02 · Istirahat 1 jam dibayar — shift dihitung 8 jam kerja

Shift Pagi 09:00–17:00 dan Shift Malam 17:00–01:00 keduanya bernilai 8 jam kerja
berbayar. Tidak ada scan istirahat.

**Alasan.** Sesuai brief (tidak perlu fingerprint saat istirahat). Kalau
istirahat dipotong tanpa data scan, sistem hanya bisa memotong 1 jam secara buta
— termasuk di hari sibuk saat karyawan sebenarnya tidak sempat istirahat, yang
justru merugikan orang yang paling keras bekerja.

**Konsekuensi.**
- Ambang lembur = jam pulang shift (17:00 / 01:00). Menit setelah itu baru
  kandidat lembur, dan hanya jadi lembur kalau ada approval.
- Pulang cepat dihitung dari jam pulang shift.
- Tarif per jam = gaji pokok ÷ (hari kerja × 8). Disimpan sebagai rule, bukan
  hard-code.
- `shifts` tetap menyimpan `break_minutes` dan `is_break_paid` supaya kebijakan
  ini bisa berubah tanpa migrasi.
- Menyelesaikan G-02.

### D-03 · Libur mingguan: target 1 hari/minggu, sifatnya peringatan

Sistem menargetkan setiap karyawan dapat 1 hari libur per minggu dan
memperingatkan bila tidak tercapai, tapi roster tetap bisa dipublish.

**Alasan.** Dengan 18 orang (kurang 3–4 dari kebutuhan), roster yang memenuhi
semua kebutuhan minimum shift **sekaligus** libur mingguan penuh secara matematis
mustahil. Validasi yang memblokir akan membuat roster tidak pernah bisa
dipublish, dan manager akan kembali ke Excel — sistem yang tidak dipakai tidak
berguna sama sekali.

**Konsekuensi.**
- Validasi roster berjenjang: **Error** (blokir) / **Warning** (boleh lanjut) /
  **Info**. Kekurangan tenaga dan libur yang tidak terpenuhi masuk kategori
  Warning.
- Laporan "beban kerja & defisit tenaga" jadi fitur nyata, bukan tempelan —
  itulah data untuk memutuskan rekrutmen.

### D-04 · Double shift diizinkan, dengan peringatan keras

Satu karyawan boleh mengambil shift pagi dan malam di hari yang sama saat
darurat.

**Alasan.** Dengan headcount mepet, ini satu-satunya jalan keluar saat beberapa
orang sakit bersamaan. Melarangnya di sistem tidak menghentikan praktiknya —
hanya membuat praktik itu tidak tercatat, dan yang tidak tercatat tidak dibayar.

**Konsekuensi (paling teknis dari keempatnya).**
- `roster_assignments` dan `attendances` **tidak bisa** memakai unique
  `(employee_id, work_date)` seperti sekarang. Kuncinya jadi
  `(employee_id, work_date, shift_key)`.
- `attendances` yang sekarang unique `(employee_id, work_date)` harus dimigrasi.
- Perhitungan jam kerja mingguan harus menjumlahkan lintas assignment, bukan
  menghitung baris per hari.
- Warning wajib muncul: 16 jam kerja dalam sehari.

---

## Asumsi default untuk gap yang belum diputuskan

Semuanya dirancang sebagai **data**, bukan kode — jadi bisa diubah nanti tanpa
migrasi. Kalau ada yang tidak sesuai kebijakan kafe, cukup beri tahu dan saya
sesuaikan.

| ID | Gap | Asumsi yang saya pakai |
|---|---|---|
| **D-05** | G-12 Potongan alpha | 1 hari gaji pokok = gaji pokok ÷ jumlah hari terjadwal dalam periode. Tersimpan sebagai rule, bukan rumus di kode |
| **D-06** | G-13 Siapa yang mengajukan lembur | **Keduanya**. Manager bisa membuat (sesuai bagian LEMBUR di brief), karyawan bisa mengajukan (sesuai bagian PENGAJUAN). Dibedakan kolom `initiated_by`. Approval manager tetap wajib di kedua jalur |
| **D-07** | G-09/G-10 PPh 21 & THR | Tidak diimplementasi sekarang. `payslip_items` punya `category` + `is_taxable` supaya bisa ditambah tanpa ubah struktur |
| **D-08** | G-06 Masuk/resign tengah periode | Proporsional berdasarkan hari terjadwal dalam periode, bukan hari kalender |
| **D-09** | G-03 Kuota cuti | Cuti tahunan 12 hari/tahun, carry over 0 hari (configurable). Sakit dengan surat dokter dibayar, tanpa surat dipotong. Izin tidak dibayar |
| **D-10** | G-04 Jeda antar shift | `min_rest_hours` = 10, `max_consecutive_days` = 6. Level Warning |
| **D-11** | G-11 Koreksi setelah lock | Adjustment ke periode berikutnya. Reopen hanya untuk kesalahan sistemik |
| **D-12** | K-06 Role | DB menyimpan 3 (`owner`, `manager`, `karyawan`), UI memperlihatkan 2 sesuai brief. `owner` = manager + kelola akun |
| **D-13** | G-14 Tipe mesin | **Masih perlu konfirmasi** (brief: Revo W-231N, komentar kode: Vivo W-2421M). Desain tidak bergantung padanya — `photo_url` tetap nullable. Kalau benar Revo (tanpa kamera), modul koreksi absensi jadi satu-satunya jalan sengketa |
| **D-14** | G-05 implementasi | Kunci unik pakai *generated column* `shift_key = COALESCE(shift_id, 0)` supaya baris LIBUR (shift_id NULL) tetap terjaga unik di MySQL |
| **D-15** | G-08 Kasbon | Dicicil, dengan jadwal cicilan per periode payroll. Payroll menarik otomatis |
| **D-16** | G-07 Riwayat gaji | `employee_salaries` dengan `effective_from`. Kolom `employees.base_salary` yang ada sekarang dimigrasi jadi baris pertama |

---

## Keputusan arsitektur yang mengikat

### D-17 · Modul absensi eksisting dipertahankan, tidak ditulis ulang

Pipeline `device_callbacks → attendance_logs` beserta strategi anti-duplikat
`scan_minute`, dua jalur masuk (webhook + cron `get_attlog`), dan `WorkWindow`
**tidak disentuh**. Yang berubah hanya lapisan di atasnya (`attendances` ke
bawah).

**Alasan.** Bagian itu sudah benar, sudah punya test, dan sudah berisi data
produksi. Menulis ulang berarti membuang kerja yang sudah terbukti dan
memperkenalkan risiko baru tanpa manfaat.

### D-18 · Absensi menyimpan fakta, payroll menyimpan uang

Tidak ada nominal rupiah di modul absensi. `attendances.deduction_amount` yang
ada sekarang dihapus.

**Alasan.** Ini yang membuat payroll lock (BR-26) benar-benar berlaku dan membuat
recompute absensi aman dijalankan kapan saja.

### D-19 · Semua aturan yang menyentuh uang punya masa berlaku dan di-snapshot

`rule_sets.effective_from` + salinan aturan disimpan di `payslip_items.rule_snapshot`.

**Alasan.** Pertanyaan "kenapa potongan Juli beda dengan Agustus" harus selalu
bisa dijawab dari data, bukan dari ingatan.

### D-20 · Roster adalah sumber kebenaran tunggal untuk jadwal

`employees.shift_id` dan `employees.off_days` turun pangkat jadi *preferensi*
untuk generator roster, bukan fakta jadwal.
