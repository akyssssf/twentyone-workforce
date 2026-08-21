<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Attendance\AttendanceComputer;
use App\Services\Attendance\WorkWindow;
use App\Support\DateInput;
use App\Support\Durasi;
use App\Support\OperationalDate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Jelaskan kenapa rekap absensi seseorang berbunyi seperti itu.
 *
 * Ada karena kegagalan paling berbahaya di alur absensi ini TIDAK menimbulkan
 * error: scan yang jatuh di luar jendela kerja dibuang diam-diam, dan rekapnya
 * tetap terlihat wajar. Orang yang datang 07:54 lalu tidak sengaja menempel
 * lagi jam 13:00 akan terbaca masuk jam 13:00, tanpa satu pun tanda bahwa scan
 * paginya pernah ada.
 *
 * Perintah ini menampilkan SELURUH scan hari itu — termasuk yang dibuang —
 * berikut alasan kenapa dibuang, supaya salah jadwal bisa dibedakan dari salah
 * scan tanpa menebak.
 */
class ExplainAttendance extends Command
{
    protected $signature = 'attendance:jelaskan
                            {pin : PIN karyawan di mesin}
                            {tanggal? : Tanggal (YYYY-MM-DD), kosong berarti hari ini}';

    protected $description = 'Jelaskan asal-usul rekap absensi seseorang pada satu tanggal';

    protected AttendanceComputer $computer;

    public function handle(AttendanceComputer $computer): int
    {
        $this->computer = $computer;

        $employee = Employee::where('pin_device', (string) $this->argument('pin'))->first();

        if ($employee === null) {
            $this->error("Tidak ada karyawan dengan PIN {$this->argument('pin')}.");

            return self::FAILURE;
        }

        $tanggal = $this->argument('tanggal')
            ? DateInput::parseOrFail((string) $this->argument('tanggal'), 'tanggal')
            : OperationalDate::today();

        $this->newLine();
        $this->info(sprintf('%s (PIN %s) — %s',
            $employee->name,
            $employee->pin_device,
            $tanggal->translatedFormat('l, d F Y')));

        $jejak = collect($computer->jejak($employee, $tanggal));

        if ($jejak->isEmpty() || $jejak->every(fn ($b) => $b['shift'] === null)) {
            $this->newLine();
            $this->warn('Tidak ada shift sama sekali untuk tanggal ini — tidak ada roster, dan tidak ada shift preferensi yang bisa ditebak.');
            $this->baris($employee, $tanggal, collect());

            return self::SUCCESS;
        }

        $this->jadwal($jejak, $tanggal);
        $this->scan($employee, $tanggal, $jejak);
        $this->hasil($jejak, $tanggal);

        return self::SUCCESS;
    }

    /** Jadwal & jendela: jam mana yang sebenarnya dipakai hari itu. */
    protected function jadwal(Collection $jejak, Carbon $tanggal): void
    {
        $this->newLine();
        $this->line('<comment>JADWAL</comment>');

        foreach ($jejak as $b) {
            if ($b['shift'] === null) {
                continue;
            }

            $shift = $b['shift'];
            $a = $b['assignment'];

            $master = substr((string) $shift->start_time, 0, 5).'–'.substr((string) $shift->end_time, 0, 5);

            $jam = $a?->start_time_override !== null
                ? sprintf('jam khusus %s–%s (master %s)',
                    substr((string) $a->start_time_override, 0, 5),
                    substr((string) $a->end_time_override, 0, 5),
                    $master)
                : "jam master {$master}";

            $asal = $b['ditebak']
                ? '<fg=yellow>TEBAKAN dari jam scan — tidak ada baris roster</>'
                : 'dari roster'.($a?->source ? " ({$a->source})" : '');

            $this->line(sprintf('  %s · %s · %s', $shift->name, $jam, $asal));

            if ($a?->division !== null) {
                $this->line("     divisi {$a->division->name}, status {$a->status->value}");
            }

            /** @var WorkWindow $w */
            $w = $b['window'];

            $this->line(sprintf('     masuk terjadwal %s, pulang terjadwal %s',
                $this->jam($w->scheduledIn, $tanggal),
                $this->jam($w->scheduledOut, $tanggal)));

            $this->line(sprintf('     <fg=cyan>jendela scan %s s/d %s</> — scan di luar ini tidak dianggap milik hari ini',
                $this->jam($w->start, $tanggal),
                $this->jam($w->end, $tanggal)));
        }
    }

    /** Semua scan hari itu, termasuk yang dibuang. Ini inti perintahnya. */
    protected function scan(Employee $employee, Carbon $tanggal, Collection $jejak): void
    {
        $strategi = (string) config('attendance.check_in_out_strategy');

        $this->newLine();
        $this->line('<comment>SEMUA SCAN</comment>');
        $this->line(sprintf('  <fg=gray>strategi jam masuk/pulang: %s — %s</>',
            $strategi,
            $strategi === 'status_scan'
                ? 'ikut tombol fungsi mesin, jadi jam masuk BELUM TENTU scan paling awal'
                : 'scan paling awal di jendela jadi jam masuk'));

        $this->baris($employee, $tanggal, $jejak);
    }

    protected function baris(Employee $employee, Carbon $tanggal, Collection $jejak): void
    {
        $timezone = config('attendance.timezone', 'Asia/Jakarta');
        $logs = $this->computer->scanHarian($employee, $tanggal, $jejak->all());

        if ($logs->isEmpty()) {
            $this->line('  (tidak ada scan sama sekali di rentang ini)');

            return;
        }

        $dibuang = 0;

        foreach ($logs as $log) {
            $waktu = $log->scanned_at->copy()->setTimezone($timezone);
            $milik = $this->windowPemilik($waktu, $jejak);

            if ($milik === null) {
                $dibuang++;
                $this->line(sprintf('  <fg=red>%s</>  %-8s <fg=red>DIABAIKAN</> — %s',
                    $this->jam($waktu, $tanggal),
                    $log->source,
                    $this->alasanDibuang($waktu, $jejak, $tanggal)));

                continue;
            }

            $this->line(sprintf('  %s  %-8s masuk jendela %s%s',
                $this->jam($waktu, $tanggal),
                $log->source,
                $milik['shift']->name,
                $this->peran($waktu, $milik)));
        }

        if ($dibuang > 0) {
            $this->newLine();
            $this->warn(sprintf('%d scan tidak masuk rekap.', $dibuang));
            $this->line('  Kalau salah satunya sebenarnya jam masuk yang benar, yang salah jadwalnya,');
            $this->line('  bukan scannya — perbaiki dengan <info>roster:set</info> atau <info>roster:jam-khusus</info>, lalu hitung ulang.');
            $this->line('  Kalau jadwalnya memang sudah benar, pakai koreksi jam masuk lewat halaman admin.');
        }
    }

    /** Jendela mana yang memuat scan ini. Null berarti tidak ada yang memuat. */
    protected function windowPemilik(Carbon $waktu, Collection $jejak): ?array
    {
        foreach ($jejak as $b) {
            if ($b['shift'] !== null && $b['window']->contains($waktu)) {
                return $b;
            }
        }

        return null;
    }

    /** Ditandai kalau scan ini yang akhirnya jadi jam masuk / jam pulang. */
    protected function peran(Carbon $waktu, array $milik): string
    {
        $peran = [];

        if ($milik['check_in']?->equalTo($waktu)) {
            $peran[] = 'JAM MASUK';
        }

        if ($milik['check_out']?->equalTo($waktu)) {
            $peran[] = 'JAM PULANG';
        }

        return $peran === [] ? '' : '  <info><- '.implode(' & ', $peran).'</info>';
    }

    /**
     * Kenapa scan ini dibuang, diukur ke jendela TERDEKAT — bukan cuma
     * "di luar jendela", karena selisihnya yang memberi tahu apakah ini salah
     * jadwal beberapa jam atau scan nyasar di hari yang salah.
     */
    protected function alasanDibuang(Carbon $waktu, Collection $jejak, Carbon $tanggal): string
    {
        $terdekat = null;
        $jarak = null;

        foreach ($jejak as $b) {
            if ($b['shift'] === null) {
                continue;
            }

            /** @var WorkWindow $w */
            $w = $b['window'];

            $selisih = $waktu->lessThan($w->start)
                ? $w->start->getTimestamp() - $waktu->getTimestamp()
                : $waktu->getTimestamp() - $w->end->getTimestamp();

            if ($jarak === null || $selisih < $jarak) {
                $jarak = $selisih;
                $terdekat = $b;
            }
        }

        if ($terdekat === null) {
            return 'tidak ada shift hari ini';
        }

        /** @var WorkWindow $w */
        $w = $terdekat['window'];

        return $waktu->lessThan($w->start)
            ? sprintf('%s sebelum jendela %s dibuka (%s)',
                Durasi::detik((int) $jarak), $terdekat['shift']->name, $this->jam($w->start, $tanggal))
            : sprintf('%s setelah jendela %s ditutup (%s)',
                Durasi::detik((int) $jarak), $terdekat['shift']->name, $this->jam($w->end, $tanggal));
    }

    protected function hasil(Collection $jejak, Carbon $tanggal): void
    {
        $this->newLine();
        $this->line('<comment>HASIL DI REKAP</comment>');

        foreach ($jejak as $b) {
            if ($b['shift'] === null) {
                continue;
            }

            $a = $b['attendance'];

            if ($a === null) {
                $this->line(sprintf('  %s · <fg=yellow>belum ada baris rekap</> — jendelanya mungkin belum tutup, atau belum dihitung ulang',
                    $b['shift']->name));

                continue;
            }

            $this->line(sprintf('  %s · %s · masuk %s · pulang %s',
                $b['shift']->name,
                $a->status->value,
                $a->check_in_at ? $this->jam($a->check_in_at->copy()->setTimezone(config('attendance.timezone', 'Asia/Jakarta')), $tanggal) : '—',
                $a->check_out_at ? $this->jam($a->check_out_at->copy()->setTimezone(config('attendance.timezone', 'Asia/Jakarta')), $tanggal) : '—'));

            $this->line(sprintf('     telat %s · pulang cepat %s · kerja %s · lembur %s',
                Durasi::menit((int) $a->late_minutes),
                Durasi::menit((int) $a->early_leave_minutes),
                Durasi::menit((int) $a->work_minutes),
                Durasi::menit((int) $a->overtime_minutes)));

            foreach ($b['adjustments'] as $koreksi) {
                $this->line(sprintf('     <info>koreksi</info> %s%s%s',
                    $koreksi->type,
                    $koreksi->value_time ? ' → '.$koreksi->value_time->format('H:i') : '',
                    $koreksi->reason ? " ({$koreksi->reason})" : ''));
            }
        }

        $this->newLine();
    }

    /** Jam yang jatuh di tanggal berikutnya ditandai, biar tidak dikira salah baca. */
    protected function jam(Carbon $waktu, Carbon $workDate): string
    {
        // abs() wajib: diffInDays bertanda, dan arahnya di sini selalu negatif
        // (scan lebih baru dari work_date). Tanpa abs, penanda tidak pernah
        // muncul justru di kasus yang paling butuh — jam pulang shift malam.
        $beda = abs($waktu->copy()->startOfDay()->diffInDays($workDate->copy()->startOfDay()));

        return $waktu->format('H:i:s').($beda >= 1 ? ' (+1 hari)' : '');
    }
}
