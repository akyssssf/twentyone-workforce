<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Attendance\AttendanceComputer;
use App\Support\DateInput;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Bangun ulang tabel attendances dari attendance_logs.
 *
 * Aman dijalankan berkali-kali: tiap tanggal dihitung ulang dari nol, jadi
 * hasilnya selalu sama untuk data yang sama.
 *
 * CATATAN SOAL HARI LIBUR
 * Belum ada jadwal libur per karyawan di sistem ini, sehingga setiap hari
 * tanpa scan terbaca "alpha", termasuk hari libur yang sah. Status "libur"
 * sudah disiapkan di skema tapi belum ada yang mengisinya. Jangan pakai
 * jumlah alpha sebagai dasar potongan sebelum jadwal libur ada.
 *
 * CATATAN SOAL TARIF
 * deduction_amount dihitung memakai tarif yang berlaku saat command ini
 * jalan. Menghitung ulang bulan lama setelah tarif di config berubah akan
 * mengubah nominal rekap lama itu.
 */
class ComputeAttendances extends Command
{
    protected $signature = 'attendance:compute
                            {--date= : Hitung satu tanggal (YYYY-MM-DD)}
                            {--from= : Awal rentang (YYYY-MM-DD)}
                            {--to= : Akhir rentang (YYYY-MM-DD)}
                            {--days=2 : Kalau tidak ada tanggal, hitung N hari terakhir}';

    protected $description = 'Hitung ulang rekap absensi harian dari attendance_logs';

    public function handle(AttendanceComputer $computer): int
    {
        if (Employee::active()->doesntExist()) {
            $this->warn('Belum ada karyawan aktif ber-shift. Tidak ada yang bisa dihitung.');
            $this->line('Daftarkan karyawan dengan pin_device sesuai PIN di mesin lebih dulu.');

            return self::SUCCESS;
        }

        try {
            [$from, $to] = $this->resolveRange();
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $total = ['computed' => 0, 'hadir' => 0, 'alpha' => 0, 'izin' => 0, 'sakit' => 0, 'cuti' => 0, 'libur' => 0];
        $baris = [];

        for ($date = $from->copy(); $date->lessThanOrEqualTo($to); $date->addDay()) {
            $hasil = $computer->computeDate($date);

            foreach ($total as $kunci => $nilai) {
                $total[$kunci] = $nilai + ($hasil[$kunci] ?? 0);
            }

            $baris[] = [
                $date->toDateString(),
                $hasil['hadir'] ?? 0,
                $hasil['alpha'] ?? 0,
                $hasil['izin'] ?? 0,
                $hasil['sakit'] ?? 0,
                $hasil['cuti'] ?? 0,
                $hasil['libur'] ?? 0,
            ];
        }

        $this->table(['Tanggal', 'Hadir', 'Alpha', 'Izin', 'Sakit', 'Cuti', 'Libur'], $baris);

        $this->info(sprintf(
            'Selesai. %d catatan dihitung.',
            $total['computed'],
        ));

        if ($total['alpha'] > 0) {
            $this->newLine();
            $this->warn("Ada {$total['alpha']} hari terhitung alpha.");
            $this->line('Kalau itu sebenarnya hari libur, atur dengan:');
            $this->line('  <comment>php artisan employee:off-days</comment>  (libur mingguan per orang)');
            $this->line('  <comment>php artisan holiday:add</comment>       (libur bersama satu tanggal)');
            $this->line('lalu jalankan ulang command ini.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveRange(): array
    {
        $timezone = config('attendance.timezone', 'Asia/Jakarta');

        if ($tanggal = $this->option('date')) {
            $satu = $this->parseDate($tanggal, 'date');

            return [$satu, $satu->copy()];
        }

        if ($this->option('from') || $this->option('to')) {
            $from = $this->option('from')
                ? $this->parseDate($this->option('from'), 'from')
                : Carbon::today($timezone);

            $to = $this->option('to')
                ? $this->parseDate($this->option('to'), 'to')
                : Carbon::today($timezone);

            if ($from->greaterThan($to)) {
                throw new \InvalidArgumentException('--from tidak boleh melewati --to.');
            }

            return [$from, $to];
        }

        // Bawaan: dua hari terakhir. Kemarin ikut dihitung ulang karena scan
        // pulang shift malam baru lengkap setelah lewat tengah malam, dan
        // karena cron get_attlog bisa menambal data yang webhook-nya kelewat.
        $days = max(1, (int) $this->option('days'));

        return [
            Carbon::today($timezone)->subDays($days - 1),
            Carbon::today($timezone),
        ];
    }

    protected function parseDate(string $value, string $opsi): Carbon
    {
        return DateInput::parseOrFail($value, "--{$opsi}");
    }
}
