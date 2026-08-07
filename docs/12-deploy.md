# Tahap 12 — Deploy ke Server

Panduan menaikkan aplikasi dari laptop ke server yang menyala 24 jam.

> **Baru pertama kali deploy?** Pakai [panduan Hostinger](13-hostinger.md) saja —
> 30 menit, tanpa perlu mengurus server. Dokumen ini untuk yang mau kendali
> penuh atau sudah terbiasa dengan Linux.

---

## 0. Kenapa harus pindah dari laptop

Sekarang aplikasinya hidup di `php artisan serve` di localhost. Begitu laptop
ditutup atau ganti jaringan, tiga hal berhenti sekaligus:

| Yang berhenti | Akibatnya |
|---|---|
| Webhook Fingerspot | Mesin tetap merekam, tapi tidak ada yang menerima kiriman realtime |
| Cron `schedule:run` | Absensi tidak dihitung, alpha tidak ditetapkan, backup tidak jalan |
| Akses dari luar | Anda tidak bisa approve apa pun dari kampus |

Yang **tidak** hilang: scan tetap tersimpan di mesin sampai 60 hari, dan cron
`attendance:sync` akan menariknya begitu server hidup lagi. Jadi mati beberapa
jam tidak menghilangkan data — tapi mati berhari-hari mendekati batas retensi.

---

## 1. Pilihan server

Untuk 15 karyawan, kebutuhannya kecil sekali (< 300 MB data setelah 5 tahun).

| Pilihan | Perkiraan biaya | Cocok kalau |
|---|---|---|
| **VPS** (Biznet, Idcloudhost, Contabo, Hetzner) | Rp 50–150rb/bln | Mau kendali penuh, sudah pernah pegang Linux |
| **Railway / Fly.io** | Gratis–Rp 100rb/bln | Tidak mau urus server sama sekali |
| **Shared hosting** dengan SSH | Rp 30–80rb/bln | Paling murah, tapi cron dan queue sering dibatasi |

Spesifikasi minimum: **1 GB RAM, 1 vCPU, 10 GB disk, PHP 8.3+**.

Catatan tentang SQLite: bagus untuk skala ini dan backup-nya cuma satu berkas,
tapi **jangan pilih hosting yang menyimpan berkas di disk sementara** (beberapa
platform PaaS menghapus berkas tiap deploy). Kalau platformnya begitu, pindah ke
MySQL — skemanya sudah ditulis portabel, jadi cukup ganti `DB_CONNECTION`.

---

## 2. Langkah pasang di VPS

Diasumsikan Ubuntu 24.04 dan domain sudah mengarah ke IP server.

### 2.1 Paket dasar

```bash
sudo apt update && sudo apt install -y nginx git unzip \
  php8.3-fpm php8.3-cli php8.3-sqlite3 php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-zip php8.3-gd
```

Composer dan Node:

```bash
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
```

### 2.2 Ambil kode

```bash
sudo mkdir -p /var/www/kafe && sudo chown $USER:$USER /var/www/kafe
git clone https://github.com/akyssssf/twentyone-workforce.git /var/www/kafe
cd /var/www/kafe
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

### 2.3 Konfigurasi

```bash
cp .env.example .env
php artisan key:generate
```

Ubah di `.env`:

```dotenv
APP_NAME="Absensi Kafe"
APP_ENV=production
APP_DEBUG=false            # WAJIB. true di produksi membocorkan isi database di halaman error.
APP_URL=https://absensi.namakafe.com
APP_TIMEZONE=Asia/Jakarta
APP_LOCALE=id

DB_CONNECTION=sqlite
DB_FOREIGN_KEYS=true       # WAJIB. Tanpa ini relasi antar tabel tidak ditegakkan sama sekali.

FINGERSPOT_API_TOKEN=...
FINGERSPOT_CLOUD_ID=GQ5179086
FINGERSPOT_WEBHOOK_SECRET=   # isi dengan hasil: openssl rand -hex 32

WHATSAPP_DRIVER=fonnte
FONNTE_TOKEN=...
WHATSAPP_ADMIN_NUMBER=6285876163554
```

### 2.4 Siapkan database

```bash
touch database/database.sqlite
php artisan migrate --force
php artisan db:seed --class=MasterDataSeeder --force
```

> `DemoSeeder` **jangan** dijalankan di produksi — ia menghapus seluruh
> karyawan dan menggantinya dengan data contoh. `DatabaseSeeder` sudah
> menjaganya (`if (! app()->environment('production'))`), tapi jangan
> memanggilnya langsung.

Buat karyawan sungguhan lewat CLI:

```bash
php artisan employee:import daftar-karyawan.csv   # pin_device,name,phone,shift,base_salary,joined_at
php artisan user:add                              # akun admin
```

### 2.5 Hak akses berkas

```bash
sudo chown -R www-data:www-data storage bootstrap/cache database public/avatars
sudo chmod -R 775 storage bootstrap/cache database
```

Folder `database` harus bisa ditulis, bukan cuma berkasnya — SQLite membuat
berkas `-wal` dan `-shm` di sebelahnya saat mode WAL aktif.

### 2.6 Foto karyawan

`public/avatars/` sengaja tidak masuk git (repo ini publik, isinya foto wajah
orang sungguhan). Salin manual:

```bash
scp -r public/avatars user@server:/var/www/kafe/public/avatars
```

Tanpa itu aplikasi tetap jalan: `avatarUrl()` mundur ke foto scan dari mesin,
lalu ke inisial nama.

### 2.7 Nginx + HTTPS

```nginx
server {
    listen 80;
    server_name absensi.namakafe.com;
    root /var/www/kafe/public;

    index index.php;
    charset utf-8;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/kafe /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d absensi.namakafe.com
```

HTTPS bukan opsional: login mengirim kata sandi, dan slip gaji berisi angka
gaji semua orang.

### 2.8 Cache produksi

```bash
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Ulangi tiga perintah ini **setiap kali `.env` berubah**, kalau tidak perubahan
tidak terbaca.

---

## 3. Proses latar belakang

Dua hal yang harus jalan terus. Tanpa keduanya, aplikasi terlihat normal tapi
absensi tidak pernah terhitung dan WhatsApp tidak pernah terkirim.

### 3.1 Scheduler (cron)

```bash
crontab -e -u www-data
```

```cron
* * * * * cd /var/www/kafe && php artisan schedule:run >> /dev/null 2>&1
```

Yang berjalan di dalamnya:

| Jadwal | Perintah | Kenapa jamnya begitu |
|---|---|---|
| tiap menit | `attendance:parse-callbacks` | Dashboard harus segar |
| tiap 15 menit | `attendance:compute` | Kemarin ikut, karena scan pulang shift malam baru lengkap lewat tengah malam |
| 02:00 | `attendance:sync` | Shift malam sudah bubar (pulang 01:00) |
| 03:00 | `db:backup` | Setelah sync selesai, jadi salinannya memuat kemarin utuh |
| 06:00 | `attendance:compute --days=2` | Jendela shift malam baru tutup 05:00 |

### 3.2 Worker antrean

WhatsApp dikirim lewat antrean, bukan di dalam permintaan HTTP — gateway bisa
menggantung belasan detik dan admin tidak boleh menunggu.

`/etc/systemd/system/kafe-queue.service`:

```ini
[Unit]
Description=Antrean Absensi Kafe
After=network.target

[Service]
User=www-data
Restart=always
RestartSec=5
ExecStart=/usr/bin/php /var/www/kafe/artisan queue:work --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now kafe-queue
sudo systemctl status kafe-queue
```

---

## 4. Sambungkan mesin Fingerspot

Di panel [developer.fingerspot.io](https://developer.fingerspot.io), daftarkan
URL webhook:

```
https://absensi.namakafe.com/api/fingerspot/webhook/{FINGERSPOT_WEBHOOK_SECRET}
```

Uji koneksinya:

```bash
php artisan fingerspot:check
php artisan attendance:sync      # tarik 2 hari terakhir
php artisan attendance:status    # lihat kondisi data
```

Kalau webhook belum jalan, absensi tetap terisi dari cron `attendance:sync`,
hanya tertunda maksimal 24 jam. Dua jalur ini memang sengaja saling menambal.

---

## 5. WhatsApp

1. Daftar di [fonnte.com](https://fonnte.com), sambungkan nomor lewat pindai QR.
2. Salin token ke `FONNTE_TOKEN` di `.env`.
3. `WHATSAPP_DRIVER=fonnte`, lalu `php artisan config:cache`.
4. Uji: tugaskan lembur ke satu orang, pastikan kodenya sampai.

Nomor yang dipakai adalah nomor WhatsApp biasa, bukan nomor bisnis resmi.
Untuk 15 karyawan dengan beberapa pesan per hari itu jauh dari ambang blokir —
tapi jangan dipakai menyiarkan pesan massal.

**Isi nomor HP semua karyawan** lewat Karyawan → detail → WhatsApp. Yang belum
diisi ditandai "Tanpa WA" di daftar karyawan, dan kegagalannya tercatat di
`notification_deliveries` — tidak hilang diam-diam.

---

## 6. Yang wajib dilakukan sebelum dipakai sungguhan

- [ ] `APP_DEBUG=false` dan `APP_ENV=production`
- [ ] Ganti sandi `admin` dari `admin123`
- [ ] Bagikan sandi awal ke tiap karyawan — mereka **wajib menggantinya** saat
      login pertama, aplikasi menahannya sampai diganti
- [ ] `FINGERSPOT_WEBHOOK_SECRET` diisi hasil acak, bukan tebakan
- [ ] HTTPS aktif dan `http://` dialihkan ke `https://`
- [ ] Isi nomor WhatsApp semua karyawan
- [ ] Uji satu putaran penuh: scan → absensi muncul → ajukan cuti → pengganti
      setuju → admin approve → payroll → slip
- [ ] Pastikan `php artisan db:backup` menghasilkan berkas di
      `storage/app/backups`
- [ ] **Salin backup ke luar server** (lihat §7)

---

## 7. Backup

Salinan harian sudah otomatis ke `storage/app/backups` (14 terakhir disimpan).
Dipakai `VACUUM INTO`, bukan menyalin berkas — menyalin berkas yang sedang
ditulis menghasilkan salinan rusak yang baru ketahuan saat dibutuhkan.

**Salinan di server yang sama bukan backup.** Kalau servernya hilang, salinannya
ikut hilang. Tambahkan sinkron ke luar:

```cron
30 3 * * * rclone copy /var/www/kafe/storage/app/backups gdrive:backup-kafe
```

Memulihkan:

```bash
sudo systemctl stop kafe-queue
cp storage/app/backups/db-2026-08-07_030000.sqlite database/database.sqlite
sudo chown www-data:www-data database/database.sqlite
sudo systemctl start kafe-queue
```

---

## 8. Memperbarui aplikasi

```bash
cd /var/www/kafe
php artisan down
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl restart kafe-queue
php artisan up
```

**Sebelum migrasi yang menyentuh tabel berisi data, salin dulu databasenya.**
`php artisan db:backup` cukup — dan itu backup termurah yang pernah ada,
manfaatkan.

---

## 9. Kalau ada yang tidak beres

| Gejala | Periksa |
|---|---|
| Absensi tidak bertambah | `php artisan attendance:status`, lalu cron `schedule:run` jalan? |
| Scan masuk tapi tidak masuk rekap | PIN belum dipetakan — lihat peringatan "PIN belum terdaftar" di dashboard |
| WhatsApp tidak terkirim | `systemctl status kafe-queue`, lalu tabel `notification_deliveries` kolom `error` |
| `database is locked` | Pastikan `journal_mode=WAL` aktif; kalau sering, saatnya pindah ke MySQL |
| Halaman error putih | `storage/logs/laravel.log`. Kalau `APP_DEBUG=false` (memang seharusnya), detailnya cuma ada di log |
| Perubahan `.env` tidak terbaca | `php artisan config:cache` belum diulang |

---

## 10. Yang belum ada dan sebaiknya menyusul

Bukan penghalang untuk mulai dipakai, tapi catat sebagai pekerjaan berikutnya:

- **Slip PDF sungguhan** — sekarang lewat cetak browser
- **CRUD karyawan lewat UI** — masih CLI (`employee:add`, `employee:import`)
- **Unggah manual berkas ekspor mesin** — tabelnya sudah ada, layarnya belum
- **Halaman rekonsiliasi** — PIN tak dikenal baru muncul sebagai peringatan
- **2FA untuk akun admin** — sekarang baru ada pembatas 5 percobaan login
- **REST API** — sengaja ditunda; logika bisnisnya sudah di service layer
