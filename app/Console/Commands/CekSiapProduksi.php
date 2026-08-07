<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

/**
 * Daftar periksa sebelum aplikasi dipakai sungguhan.
 *
 * Dibuat sebagai command, bukan sekadar daftar di dokumen, karena daftar di
 * dokumen dibaca sekali lalu dilupakan. Yang ini bisa dijalankan lagi setiap
 * habis mengubah setelan, dan menjawab dengan keadaan sebenarnya di server —
 * bukan dengan ingatan.
 */
class CekSiapProduksi extends Command
{
    protected $signature = 'app:cek-siap';

    protected $description = 'Periksa apakah aplikasi aman dipakai sungguhan';

    protected array $masalah = [];

    protected array $peringatan = [];

    public function handle(): int
    {
        $this->info('Memeriksa kesiapan...');
        $this->newLine();

        $this->cekKeamanan();
        $this->cekDatabase();
        $this->cekMesin();
        $this->cekWhatsApp();
        $this->cekData();
        $this->cekBerkas();

        $this->newLine();

        foreach ($this->masalah as $m) {
            $this->line("  <fg=red>✗</> {$m}");
        }

        foreach ($this->peringatan as $p) {
            $this->line("  <fg=yellow>!</> {$p}");
        }

        $this->newLine();

        if ($this->masalah !== []) {
            $this->error(count($this->masalah).' hal harus dibereskan sebelum dipakai sungguhan.');

            return self::FAILURE;
        }

        if ($this->peringatan !== []) {
            $this->warn(count($this->peringatan).' hal sebaiknya dibereskan, tapi tidak menghalangi.');

            return self::SUCCESS;
        }

        $this->info('Semua siap.');

        return self::SUCCESS;
    }

    protected function cekKeamanan(): void
    {
        if (config('app.debug')) {
            // Yang paling berbahaya di daftar ini. Halaman error Laravel
            // menampilkan isi variabel — termasuk baris database dan isi .env.
            $this->masalah[] = 'APP_DEBUG masih true. Halaman error akan membocorkan isi database dan .env.';
        }

        if (config('app.env') !== 'production') {
            $this->peringatan[] = 'APP_ENV bukan "production" (sekarang: '.config('app.env').').';
        }

        if (blank(config('app.key'))) {
            $this->masalah[] = 'APP_KEY kosong. Jalankan: php artisan key:generate';
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $this->masalah[] = 'APP_URL belum https. Login mengirim kata sandi, dan slip gaji berisi angka gaji semua orang.';
        }

        // Sandi bawaan seeder. Kalau masih dipakai, siapa pun yang pernah
        // melihat dokumen ini bisa masuk sebagai admin.
        $admin = User::query()->where('username', 'admin')->first();

        if ($admin && Hash::check('admin123', $admin->password)) {
            $this->masalah[] = 'Akun "admin" masih memakai sandi bawaan admin123.';
        }
    }

    protected function cekDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $foreignKeys = DB::select('PRAGMA foreign_keys')[0]->foreign_keys ?? 0;

            if (! $foreignKeys) {
                $this->masalah[] = 'Foreign key SQLite mati. Isi DB_FOREIGN_KEYS=true di .env.';
            }

            $journal = DB::select('PRAGMA journal_mode')[0]->journal_mode ?? '';

            if (strtolower($journal) !== 'wal') {
                $this->peringatan[] = "Mode jurnal SQLite \"{$journal}\", bukan WAL. Tanpa WAL, laporan yang sedang dibuka bisa menahan scan yang masuk.";
            }

            $berkas = database_path('database.sqlite');

            if (file_exists($berkas) && ! is_writable(dirname($berkas))) {
                $this->masalah[] = 'Folder database/ tidak bisa ditulis. SQLite butuh menulis berkas -wal dan -shm di sebelahnya.';
            }
        }
    }

    protected function cekMesin(): void
    {
        if (blank(config('fingerspot.api_token'))) {
            $this->masalah[] = 'FINGERSPOT_API_TOKEN kosong. Cron penambal tidak bisa menarik scan dari mesin.';
        }

        if (blank(config('fingerspot.cloud_id'))) {
            $this->peringatan[] = 'FINGERSPOT_CLOUD_ID kosong. Pencocokan scan mundur ke pencarian tanpa SN mesin.';
        }

        $rahasia = (string) config('fingerspot.webhook_secret');

        if (blank($rahasia)) {
            $this->masalah[] = 'FINGERSPOT_WEBHOOK_SECRET kosong. Webhook mesin tidak akan diterima.';
        } elseif (strlen($rahasia) < 32) {
            $this->masalah[] = 'FINGERSPOT_WEBHOOK_SECRET terlalu pendek. Itu satu-satunya pengaman URL webhook — isi hasil: openssl rand -hex 32';
        }
    }

    protected function cekWhatsApp(): void
    {
        $driver = config('whatsapp.driver');

        if ($driver === 'log') {
            $this->peringatan[] = 'WHATSAPP_DRIVER masih "log" — kode lembur tidak benar-benar terkirim, cuma dicatat di log.';

            return;
        }

        if ($driver === 'fonnte' && blank(config('whatsapp.fonnte.token'))) {
            $this->masalah[] = 'WHATSAPP_DRIVER=fonnte tapi FONNTE_TOKEN kosong.';
        }

        if ($driver === 'cloud' && (blank(config('whatsapp.cloud.token')) || blank(config('whatsapp.cloud.phone_number_id')))) {
            $this->masalah[] = 'WHATSAPP_DRIVER=cloud tapi WHATSAPP_CLOUD_TOKEN / WHATSAPP_CLOUD_PHONE_ID kosong.';
        }
    }

    protected function cekData(): void
    {
        $tanpaNomor = Employee::query()->active()->whereNull('phone')->count();

        if ($tanpaNomor > 0) {
            $this->peringatan[] = "{$tanpaNomor} karyawan belum punya nomor WhatsApp — mereka tidak akan menerima kode lembur.";
        }

        $tanpaAkun = Employee::query()->active()->doesntHave('user')->count();

        if ($tanpaAkun > 0) {
            $this->peringatan[] = "{$tanpaAkun} karyawan belum punya akun login.";
        }

        $belumGanti = User::query()->where('must_change_password', true)->count();

        if ($belumGanti > 0) {
            $this->peringatan[] = "{$belumGanti} akun belum mengganti sandi awal (aplikasi menahannya sampai diganti).";
        }
    }

    protected function cekBerkas(): void
    {
        foreach (['storage', 'bootstrap/cache'] as $folder) {
            if (! is_writable(base_path($folder))) {
                $this->masalah[] = "Folder {$folder} tidak bisa ditulis.";
            }
        }

        if (! File::exists(public_path('build/manifest.json'))) {
            $this->masalah[] = 'Aset belum dibangun (public/build/manifest.json tidak ada). Jalankan npm run build lalu unggah folder public/build.';
        }
    }
}
