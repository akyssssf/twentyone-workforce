# Deploy di Hostinger (versi simpel)

Kalau Anda cuma mau aplikasinya online tanpa mengurus server, ini jalurnya.
Tidak perlu VPS, tidak perlu Linux, tidak perlu terminal (kecuali sekali di
langkah 4, dan itu pun cuma menempel beberapa baris).

**Perkiraan waktu: 30–45 menit.**

> Kalau nanti kafe berkembang dan butuh kendali lebih, [panduan VPS](12-deploy.md)
> tetap ada. Isinya sama, cuma lebih panjang.

---

## Yang perlu disiapkan

| Barang | Keterangan |
|---|---|
| Paket Hostinger | **Premium** ke atas. Yang penting: ada **SSH**, **Cron Job**, dan PHP 8.3 |
| Domain | Boleh subdomain gratis dari Hostinger, boleh domain sendiri |
| Token Fingerspot | Dari developer.fingerspot.io |
| Token Fonnte | Untuk WhatsApp. Bisa menyusul — aplikasi tetap jalan tanpanya |

> Paket paling murah (Single) biasanya **tanpa SSH**. Tanpa SSH, `composer install`
> dan `php artisan migrate` tidak bisa dijalankan, dan deploy jadi jauh lebih
> rumit. Pastikan paketnya punya SSH sebelum membeli.

---

## Langkah 1 — Buat website & aktifkan SSL

1. hPanel → **Websites** → **Add Website** → pilih domain/subdomain.
2. Setelah jadi, masuk ke **Advanced → SSL** → aktifkan **Free SSL**.
3. Nyalakan **Force HTTPS**.

HTTPS bukan hiasan: login mengirim kata sandi, dan slip gaji berisi angka gaji
semua orang.

---

## Langkah 2 — Arahkan folder utama ke `public`

Ini satu-satunya bagian yang beda dari website PHP biasa, dan yang paling
sering salah.

hPanel → **Websites → Dashboard → Advanced → Change Website Root Folder**,
isi dengan:

```
public_html/kafe/public
```

Kenapa: Laravel menaruh berkas rahasia (`.env`, kode program) satu tingkat di
atas folder yang dilayani ke internet. Kalau folder utamanya salah, isi `.env`
— termasuk token Fingerspot dan kunci aplikasi — bisa dibuka lewat peramban.

> **Kalau menu itu tidak ada di paket Anda**, lewati saja. Sudah ada berkas
> `.htaccess` di akar proyek yang menutup lubang itu sebagai cadangan. Tapi
> mengubah folder utama tetap cara yang lebih benar kalau bisa.

---

## Langkah 3 — Ambil kode lewat Git

hPanel → **Advanced → GIT** → **Create New Repository**:

| Kolom | Isi |
|---|---|
| Repository | `https://github.com/akyssssf/twentyone-workforce.git` |
| Branch | `main` |
| Directory | `kafe` |

Klik **Create**. Setelah selesai, tombol **Deploy** di halaman yang sama
dipakai setiap kali mau menarik pembaruan.

Tidak perlu menjalankan `npm run build` di server — hasil build (`public/build`)
sudah ikut di dalam repo justru supaya langkah ini tidak dibutuhkan.

---

## Langkah 4 — Pasang lewat SSH (sekali saja)

hPanel → **Advanced → SSH Access**, salin perintah SSH-nya, lalu tempel di
Terminal (Mac) atau PowerShell (Windows).

Setelah masuk, tempel blok ini seluruhnya:

```bash
cd ~/public_html/kafe

# Pustaka PHP
composer install --no-dev --optimize-autoloader

# Berkas setelan
cp .env.example .env
php artisan key:generate

# Database (berkas tunggal, tidak perlu setup apa pun)
touch database/database.sqlite
php artisan migrate --force
php artisan db:seed --class=MasterDataSeeder --force

# Izin tulis
chmod -R 775 storage bootstrap/cache database
```

> `MasterDataSeeder` mengisi divisi, shift, aturan potongan, dan setelan awal.
> **Jangan** jalankan `db:seed` polos — di dalamnya ada `DemoSeeder` yang
> menghapus seluruh karyawan dan menggantinya dengan data contoh.

---

## Langkah 5 — Isi `.env`

hPanel → **File Manager** → masuk `public_html/kafe` → klik kanan `.env` →
**Edit**. Ubah baris-baris ini:

```dotenv
APP_NAME="Absensi Kafe"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://absensi.domainanda.com
APP_TIMEZONE=Asia/Jakarta
APP_LOCALE=id

DB_CONNECTION=sqlite
DB_FOREIGN_KEYS=true

FINGERSPOT_API_TOKEN=token_dari_fingerspot
FINGERSPOT_CLOUD_ID=GQ5179086
FINGERSPOT_WEBHOOK_SECRET=isi_dengan_acakan_panjang

WHATSAPP_DRIVER=fonnte
FONNTE_TOKEN=token_dari_fonnte
WHATSAPP_ADMIN_NUMBER=6285876163554
```

Untuk `FINGERSPOT_WEBHOOK_SECRET`, jalankan di SSH: `openssl rand -hex 32`,
lalu salin hasilnya. **Jangan** diisi tebakan — itu satu-satunya pengaman URL
webhook.

Simpan, lalu di SSH jalankan:

```bash
cd ~/public_html/kafe && php artisan config:cache
```

Ulangi perintah ini **setiap kali `.env` diubah**, kalau tidak perubahannya
tidak terbaca.

---

## Langkah 6 — Satu cron job

hPanel → **Advanced → Cron Jobs** → **Create New Cron Job**:

| Kolom | Isi |
|---|---|
| Type | Custom |
| Interval | Setiap menit (`* * * * *`) |
| Command | `/usr/bin/php ~/public_html/kafe/artisan schedule:run` |

Satu baris ini menjalankan semuanya: menyerap scan dari mesin, menghitung
absensi, mengirim WhatsApp, dan membuat salinan database harian.

> **Kalau paket Anda tidak mengizinkan interval 1 menit** (beberapa paket
> minimal 5 menit), tidak apa-apa. Akibatnya cuma dashboard telat maksimal 5
> menit dan WhatsApp terkirim maksimal 5 menit setelah disetujui. Absensi,
> payroll, dan backup tetap benar.

---

## Langkah 7 — Sambungkan mesin Fingerspot

Di [developer.fingerspot.io](https://developer.fingerspot.io), daftarkan URL
webhook:

```
https://absensi.domainanda.com/api/fingerspot/webhook/RAHASIA_YANG_TADI
```

Ganti `RAHASIA_YANG_TADI` dengan isi `FINGERSPOT_WEBHOOK_SECRET`.

Uji di SSH:

```bash
cd ~/public_html/kafe
php artisan fingerspot:check     # cek koneksi ke mesin
php artisan attendance:sync      # tarik 2 hari terakhir
php artisan attendance:status    # lihat hasilnya
```

Kalau webhook belum jalan pun tidak masalah — cron `attendance:sync` tetap
menarik data setiap jam 02:00. Dua jalur ini memang sengaja saling menambal.

---

## Langkah 8 — Periksa kesiapan

```bash
cd ~/public_html/kafe && php artisan app:cek-siap
```

Perintah ini memeriksa keadaan sebenarnya di server: `APP_DEBUG`, HTTPS, sandi
admin bawaan, foreign key, token mesin, token WhatsApp, izin folder, dan
karyawan yang belum punya nomor.

Bereskan semua tanda `✗` sebelum dipakai sungguhan. Tanda `!` boleh menyusul.

---

## Langkah 9 — Isi data karyawan

Buat akun admin:

```bash
php artisan user:add
```

Lalu masuk lewat peramban dan isi karyawan. Untuk 15 orang, lewat SSH lebih
cepat:

```bash
php artisan employee:add        # satu per satu, interaktif
php artisan employee:import daftar.csv   # atau sekaligus lewat CSV
```

Format CSV: `pin_device,name,phone,shift,base_salary,joined_at`

Setelah itu, lewat aplikasi:

1. **Karyawan → detail → WhatsApp** — isi nomor semua orang. Tanpa nomor,
   kode lembur tidak akan sampai.
2. **Karyawan → detail → Divisi** — tentukan divisi masing-masing.
3. **Karyawan → detail → Absensi** — matikan untuk admin yang tidak menempel
   jari di mesin.
4. **Roster** → buat bulan berjalan → Isi otomatis → Terbitkan.

---

## Langkah 10 — Foto karyawan (opsional)

Foto wajah tidak ikut di repo karena reponya publik. Kalau mau dipasang:

File Manager → masuk `public_html/kafe/public` → buat folder `avatars` →
unggah berkas `pin_1.jpg`, `pin_2.jpg`, dan seterusnya (sesuai PIN di mesin).

Tanpa itu aplikasi tetap jalan: foto diambil dari hasil scan mesin, dan kalau
belum ada scan pun, ditampilkan inisial nama.

---

## Kalau ada pembaruan

hPanel → **Advanced → GIT** → tombol **Deploy**.

Kalau pembaruannya menyentuh database, jalankan lewat SSH:

```bash
cd ~/public_html/kafe
php artisan db:backup            # selalu ini dulu
php artisan migrate --force
php artisan config:cache
```

---

## Kalau ada yang tidak beres

| Gejala | Yang diperiksa |
|---|---|
| Halaman kosong / error 500 | `storage/logs/laravel.log` lewat File Manager |
| Muncul daftar berkas, bukan aplikasi | Folder utama belum diarahkan ke `public` (Langkah 2) |
| Absensi tidak bertambah | Cron sudah dibuat? Cek `php artisan attendance:status` |
| Scan masuk tapi tidak masuk rekap | PIN belum dipetakan — lihat peringatan "PIN belum terdaftar" di dashboard |
| WhatsApp tidak terkirim | `FONNTE_TOKEN` sudah diisi dan `config:cache` sudah diulang? |
| Perubahan `.env` tidak terasa | `php artisan config:cache` belum dijalankan |
| `database is locked` sesekali | Wajar, sudah ditangani. Kalau sering, lihat catatan MySQL di bawah |

---

## Catatan: SQLite atau MySQL?

Panduan ini memakai **SQLite** karena paling sederhana — satu berkas, tanpa
setup, backup tinggal menyalin. Untuk 15 karyawan itu lebih dari cukup.

**Pindah ke MySQL** kalau muncul `database is locked` lebih dari sekali
seminggu. Hostinger menyediakannya gratis. Skema aplikasi ini sudah diuji jalan
penuh di MySQL — migrasi, roster, pengajuan, sampai payroll.

Caranya: buat database di hPanel → **Databases → MySQL Databases**, lalu ubah
`.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=u123456_kafe
DB_USERNAME=u123456_kafe
DB_PASSWORD=sandi_dari_hpanel
```

Lalu `php artisan migrate --force` dan `php artisan config:cache`.

> Kalau sudah ada data di SQLite, pindahkan dulu isinya — `migrate` di database
> baru menghasilkan tabel kosong, bukan salinan data lama.

---

## Backup

Salinan harian otomatis jam 03:00 ke `storage/app/backups`, 14 terakhir
disimpan. Bisa diunduh lewat File Manager.

**Salinan di server yang sama bukan backup.** Kalau akun hosting bermasalah,
salinannya ikut hilang. Sebulan sekali, unduh satu berkas terbaru dan simpan di
Google Drive. Lima detik kerja, dan itu satu-satunya hal yang menyelamatkan
data gaji kalau terjadi sesuatu.
