<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\DeviceCallback;
use App\Models\Employee;
use App\Support\Durasi;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Jendela pemantau untuk tes hardware.
 *
 * Saat menempelkan jari di mesin, yang ingin diketahui cuma satu: datanya
 * sampai atau tidak, dan kalau sampai jadi apa. Command ini menjawab itu
 * dalam satu layar, dari callback mentah sampai rekap jadinya.
 */
class AttendanceStatus extends Command
{
    protected $signature = 'attendance:status
                            {--date= : Tanggal yang dilihat (YYYY-MM-DD), bawaannya hari ini}';

    protected $description = 'Lihat aliran data absensi hari ini, dari callback mentah sampai rekap';

    public function handle(): int
    {
        $timezone = config('attendance.timezone', 'Asia/Jakarta');

        $date = $this->option('date')
            ? Carbon::parse($this->option('date'), $timezone)->startOfDay()
            : Carbon::today($timezone);

        $this->line("<info>Status absensi {$date->toDateString()}</info> (zona {$timezone}, sekarang ".Carbon::now($timezone)->format('H:i:s').')');

        $logs = $this->scanDi($date);

        $this->tampilkanCallback($date, $logs->isNotEmpty());
        $this->tampilkanScan($logs);
        $this->tampilkanPinAsing();
        $this->tampilkanRekap($date);

        return self::SUCCESS;
    }

    /**
     * Scan milik tanggal ini. Jendelanya dilebarkan sampai akhir hari
     * berikutnya supaya scan pulang Shift 2 ikut kelihatan.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AttendanceLog>
     */
    protected function scanDi(Carbon $date)
    {
        return AttendanceLog::query()
            ->whereBetween('scanned_at', [$date->copy(), $date->copy()->addDay()->endOfDay()])
            ->orderBy('scanned_at')
            ->get();
    }

    protected function tampilkanCallback(Carbon $date, bool $adaScan): void
    {
        $total = DeviceCallback::whereDate('received_at', $date)->count();
        $belum = DeviceCallback::whereDate('received_at', $date)->where('parsed', false)->count();
        $error = DeviceCallback::whereDate('received_at', $date)->whereNotNull('parse_error')->count();

        $this->newLine();
        $this->line("<comment>1. Callback mentah diterima {$date->toDateString()}:</comment> {$total}");

        if ($total === 0) {
            // Melihat tanggal lampau wajar tidak punya callback hari itu,
            // karena yang dihitung adalah kapan datanya diterima, bukan kapan
            // scan-nya terjadi. Jangan kirim orang mengejar masalah palsu.
            if ($adaScan) {
                $this->line('   Scan tanggal ini masuk lewat callback di hari lain, atau lewat cron sync.');

                return;
            }

            $this->line('   Belum ada callback. Kalau mesin sudah discan, periksa webhook_url-nya');
            $this->line('   dengan <comment>php artisan fingerspot:check</comment>.');

            return;
        }

        if ($belum > 0) {
            $this->line("   {$belum} belum diproses. Jalankan <comment>php artisan attendance:parse-callbacks</comment>");
            $this->line('   atau pastikan cron schedule:run hidup.');
        }

        if ($error > 0) {
            $this->warn("   {$error} gagal diparse. Lihat kolom parse_error di device_callbacks.");
        }
    }

    protected function tampilkanScan($logs): void
    {
        $this->newLine();
        $this->line("<comment>2. Scan ternormalisasi:</comment> {$logs->count()}");

        if ($logs->isEmpty()) {
            return;
        }

        $pemilik = Employee::pluck('name', 'pin_device');

        $this->table(
            ['Waktu', 'PIN', 'Karyawan', 'Verifikasi', 'Status scan', 'Sumber'],
            $logs->map(fn (AttendanceLog $log) => [
                $log->scanned_at->format('d M H:i:s'),
                $log->pin,
                $pemilik[$log->pin] ?? '<error>BELUM TERDAFTAR</error>',
                $log->verifyModeLabel() ?? '-',
                $log->statusScanLabel() ?? '-',
                $log->source,
            ])->all(),
        );
    }

    /**
     * PIN yang mesin kirim tapi tidak ada di tabel employees.
     *
     * Ini kendala paling sering saat tes hardware: jarinya terbaca, datanya
     * masuk, tapi tidak muncul di rekap mana pun karena PIN-nya belum
     * dipetakan ke karyawan.
     */
    protected function tampilkanPinAsing(): void
    {
        $terdaftar = Employee::pluck('pin_device')->all();

        $asing = AttendanceLog::query()
            ->when($terdaftar !== [], fn ($q) => $q->whereNotIn('pin', $terdaftar))
            ->distinct()
            ->pluck('pin');

        if ($asing->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->warn('PIN yang mengirim scan tapi belum terdaftar: '.$asing->implode(', '));
        $this->line('   Scan-nya tersimpan aman, tapi tidak akan masuk rekap sampai');
        $this->line('   dibuatkan employees dengan pin_device yang sama.');
    }

    protected function tampilkanRekap(Carbon $date): void
    {
        $rekap = Attendance::with('employee')
            ->whereDate('work_date', $date)
            ->get();

        $this->newLine();
        $this->line("<comment>3. Rekap terhitung:</comment> {$rekap->count()}");

        if ($rekap->isEmpty()) {
            $karyawan = Employee::active()->whereNotNull('default_shift_id')->count();

            if ($karyawan === 0) {
                $this->line('   Belum ada karyawan aktif ber-shift, jadi belum ada yang bisa dihitung.');
            } else {
                $this->line('   Jalankan <comment>php artisan attendance:compute</comment>.');
            }

            return;
        }

        $this->table(
            ['Karyawan', 'Jadwal', 'Masuk', 'Pulang', 'Telat', 'Blok', 'Potongan', 'Status'],
            $rekap->map(fn (Attendance $a) => [
                $a->employee?->name ?? '-',
                $a->scheduled_in?->format('H:i') ?? '-',
                $a->check_in_at?->format('H:i:s') ?? '-',
                $a->check_out_at?->format('H:i:s') ?? '-',
                Durasi::detik($a->late_seconds),
                $a->late_blocks ?: '-',
                $a->deduction_amount > 0 ? 'Rp '.number_format($a->deduction_amount, 0, ',', '.') : '-',
                $a->status,
            ])->all(),
        );

        $potongan = $rekap->sum('deduction_amount');

        if ($potongan > 0) {
            $this->line('   Total potongan hari ini: <comment>Rp '.number_format($potongan, 0, ',', '.').'</comment>');
        }
    }
}
