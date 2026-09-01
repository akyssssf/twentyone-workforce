<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendanceAdjustment;
use App\Models\Employee;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Attendance\WorkWindow;
use App\Support\DateInput;
use App\Support\Durasi;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * Koreksi jam masuk / jam pulang seseorang pada satu tanggal.
 *
 * Untuk kasus paling sering di kafe: orangnya benar-benar bekerja tapi lupa
 * menempel jari. Tanpa jalur ini, satu-satunya cara membetulkannya adalah
 * menunggu karyawannya mengajukan koreksi sendiri lewat aplikasi — dan orang
 * yang sudah lupa absen biasanya juga tidak mengajukan.
 *
 * Ditulis sebagai koreksi di attendance_adjustments, sama seperti approval
 * koreksi dari aplikasi. Bukan pintu belakang: cron yang menghitung ulang tiap
 * 15 menit akan menghapus perubahan langsung ke tabel attendances, sedangkan
 * koreksi hidup sebagai INPUT dan ikut diterapkan ulang setiap kali dihitung.
 */
class SetAttendanceTime extends Command
{
    protected $signature = 'attendance:jam
                            {pin : PIN karyawan di mesin}
                            {tanggal : Tanggal (YYYY-MM-DD)}
                            {--masuk= : Jam masuk sebenarnya, mis. 08:00}
                            {--pulang= : Jam pulang sebenarnya, mis. 18:00}
                            {--shift= : Kode shift, wajib kalau hari itu dia punya lebih dari satu jadwal}
                            {--alasan= : Kenapa dikoreksi}
                            {--batal : Batalkan koreksi jam yang sudah ada}';

    protected $description = 'Koreksi jam masuk atau jam pulang seseorang pada satu tanggal';

    public function handle(): int
    {
        $employee = Employee::where('pin_device', (string) $this->argument('pin'))->first();

        if ($employee === null) {
            $this->error("Tidak ada karyawan dengan PIN {$this->argument('pin')}.");

            return self::FAILURE;
        }

        $tanggal = DateInput::parseOrFail((string) $this->argument('tanggal'), 'tanggal');

        $assignment = $this->pilihJadwal($employee, $tanggal);

        if ($assignment === false) {
            return self::FAILURE;
        }

        $this->line("Karyawan: {$employee->name}  Tanggal: {$tanggal->toDateString()}");
        $this->line('Sebelum : '.$this->ringkas($employee, $tanggal));

        $shiftKey = (int) ($assignment?->shift_id ?? 0);

        if ($this->option('batal')) {
            $dibatalkan = AttendanceAdjustment::query()
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $tanggal)
                ->whereIn('type', ['set_check_in', 'set_check_out'])
                ->whereNull('reverted_by_id')
                ->get();

            if ($dibatalkan->isEmpty()) {
                $this->error('Tidak ada koreksi jam yang aktif pada tanggal itu.');

                return self::FAILURE;
            }

            foreach ($dibatalkan as $koreksi) {
                // Ditandai batal, bukan dihapus — tabel ini append-only supaya
                // jejak keputusannya tidak hilang.
                $koreksi->update(['reverted_by_id' => $koreksi->id]);
            }

            $this->info($dibatalkan->count().' koreksi jam dibatalkan.');
        } else {
            $alasan = trim((string) $this->option('alasan'));

            if ($alasan === '') {
                $this->error('Isi --alasan. Mengubah jam kerja menyangkut uang, jadi harus bisa dijelaskan nanti.');

                return self::FAILURE;
            }

            if (! $this->option('masuk') && ! $this->option('pulang')) {
                $this->error('Isi minimal salah satu: --masuk atau --pulang.');

                return self::FAILURE;
            }

            $window = $assignment?->shift !== null
                ? WorkWindow::for($assignment->shift, $tanggal, $assignment)
                : null;

            foreach (['masuk' => 'set_check_in', 'pulang' => 'set_check_out'] as $opsi => $jenis) {
                if (! $jam = $this->option($opsi)) {
                    continue;
                }

                $waktu = $this->waktu((string) $jam, $tanggal, $window, $opsi === 'pulang');

                if ($waktu === null) {
                    $this->error("Format --{$opsi} harus HH:MM, mis. 08:00 atau 18:30.");

                    return self::FAILURE;
                }

                $this->gantiKoreksiLama($employee, $tanggal, $jenis);

                AttendanceAdjustment::create([
                    'employee_id' => $employee->id,
                    'work_date' => $tanggal,
                    'shift_key' => $shiftKey,
                    'type' => $jenis,
                    'value_time' => $waktu,
                    'reason' => $alasan,
                    'approved_by' => (User::where('role', 'admin')->first() ?? User::first())?->id,
                    'approved_at' => now(),
                ]);

                $this->info(sprintf('Jam %s disetel ke %s.', $opsi, $waktu->format('Y-m-d H:i')));
            }
        }

        Artisan::call('attendance:compute', [
            '--from' => $tanggal->toDateString(),
            '--to' => $tanggal->toDateString(),
        ]);

        $this->line('Sesudah : '.$this->ringkas($employee, $tanggal));

        return self::SUCCESS;
    }

    /**
     * Jadwal yang dikoreksi. False berarti sudah dilaporkan gagal.
     *
     * Kalau hari itu dia punya lebih dari satu jadwal, shiftnya WAJIB disebut —
     * menebaknya berarti koreksi bisa menempel di shift yang salah, dan itu
     * memindahkan jam kerja dari satu shift ke shift lain tanpa ada yang sadar.
     *
     * @return RosterAssignment|null|false
     */
    protected function pilihJadwal(Employee $employee, Carbon $tanggal)
    {
        $jadwal = RosterAssignment::with('shift')
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $tanggal)
            ->working()
            ->get();

        if ($kode = $this->option('shift')) {
            $shift = Shift::where('code', $kode)->first();

            if ($shift === null) {
                $this->error("Shift dengan kode '{$kode}' tidak ada.");

                return false;
            }

            $pilihan = $jadwal->firstWhere('shift_id', $shift->id);

            if ($pilihan === null) {
                $this->error("{$employee->name} tidak dijadwalkan {$shift->name} pada tanggal itu.");

                return false;
            }

            return $pilihan;
        }

        if ($jadwal->count() > 1) {
            $this->error('Hari itu dia punya lebih dari satu jadwal — sebutkan yang mana dengan --shift.');
            $this->line('Pilihan: '.$jadwal->map(fn ($j) => $j->shift?->code)->filter()->implode(', '));

            return false;
        }

        // Tidak ada jadwal kerja sama sekali tetap boleh dikoreksi: koreksinya
        // berlaku untuk shift mana pun di tanggal itu (shift_key 0).
        return $jadwal->first();
    }

    /** Koreksi lama dibatalkan dulu supaya tidak ada dua yang aktif bersamaan. */
    protected function gantiKoreksiLama(Employee $employee, Carbon $tanggal, string $jenis): void
    {
        AttendanceAdjustment::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $tanggal)
            ->where('type', $jenis)
            ->whereNull('reverted_by_id')
            ->get()
            ->each(fn (AttendanceAdjustment $a) => $a->update(['reverted_by_id' => $a->id]));
    }

    /**
     * Jam "01:00" untuk shift malam itu tanggal BERIKUTNYA, bukan dini hari
     * tanggal yang sama. Diputuskan dari jadwalnya, bukan ditebak dari
     * angkanya: jam pulang yang jatuh sebelum jam masuk berarti sudah lewat
     * tengah malam.
     */
    protected function waktu(string $jam, Carbon $tanggal, ?WorkWindow $window, bool $pulang): ?Carbon
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($jam), $m)) {
            return null;
        }

        [$h, $i, $s] = [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];

        if ($h > 23 || $i > 59 || $s > 59) {
            return null;
        }

        $waktu = $tanggal->copy()->setTime($h, $i, $s);

        if ($pulang && $window !== null && $waktu->lessThan($window->scheduledIn)) {
            $waktu->addDay();
        }

        return $waktu;
    }

    protected function ringkas(Employee $employee, Carbon $tanggal): string
    {
        $baris = Attendance::with('shift')
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $tanggal)
            ->get();

        if ($baris->isEmpty()) {
            return '(belum ada rekap absensi untuk tanggal ini)';
        }

        return $baris->map(fn (Attendance $a) => sprintf('%s — %s, masuk %s, pulang %s, kerja %s',
            $a->shift?->name ?? '-',
            $a->status->label(),
            $a->check_in_at?->format('H:i') ?? '—',
            $a->check_out_at?->format('H:i') ?? '—',
            Durasi::menit((int) $a->work_minutes),
        ))->implode(' | ');
    }
}
