<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\RosterAssignment;
use App\Support\DateInput;
use Illuminate\Console\Command;

/**
 * Semua scan mentah satu orang dalam rentang tanggal, per tanggal kalender,
 * berdampingan dengan jadwalnya hari itu.
 *
 * attendance:jelaskan hanya menampilkan scan di sekitar jendela shift satu
 * hari. Untuk pertanyaan "dia tercatat alpha/libur, tapi apa ada scan di
 * hari-hari lain minggu itu — jangan-jangan jadwalnya yang tergeser?", yang
 * dibutuhkan justru satu pandangan lintas hari. Tidak mengubah apa pun.
 */
class ListScans extends Command
{
    protected $signature = 'attendance:scan
                            {pin? : PIN karyawan; kosong = SEMUA karyawan pada satu tanggal}
                            {--from= : Tanggal awal (YYYY-MM-DD)}
                            {--to= : Tanggal akhir, kosong berarti sama dengan --from}';

    protected $description = 'Semua scan mentah satu orang dalam rentang tanggal, berdampingan dengan jadwalnya';

    public function handle(): int
    {
        if ($this->argument('pin') === null) {
            return $this->semuaOrang();
        }

        $employee = Employee::where('pin_device', (string) $this->argument('pin'))->first();

        if ($employee === null) {
            $this->error("Tidak ada karyawan dengan PIN {$this->argument('pin')}.");

            return self::FAILURE;
        }

        if (! $this->option('from')) {
            $this->error('Isi --from, mis. --from=2026-10-12 --to=2026-10-18');

            return self::FAILURE;
        }

        $dari = DateInput::parseOrFail((string) $this->option('from'), 'from');
        $sampai = $this->option('to')
            ? DateInput::parseOrFail((string) $this->option('to'), 'to')
            : $dari->copy();

        if ($sampai->lessThan($dari)) {
            $this->error('--to lebih awal dari --from.');

            return self::FAILURE;
        }

        $timezone = config('attendance.timezone', 'Asia/Jakarta');

        // Dicari lewat PIN, bukan employee_id: scan dari PIN yang belum
        // terpetakan ke siapa pun tetap tersimpan dengan employee_id kosong.
        $logs = AttendanceLog::query()
            ->where('pin', (string) $employee->pin_device)
            ->whereBetween('scanned_at', [
                $dari->copy()->setTimezone($timezone)->startOfDay(),
                $sampai->copy()->setTimezone($timezone)->endOfDay(),
            ])
            ->orderBy('scanned_at')
            ->get()
            ->groupBy(fn (AttendanceLog $l) => $l->scanned_at->copy()->setTimezone($timezone)->toDateString());

        $jadwal = RosterAssignment::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$dari->copy()->startOfDay(), $sampai->copy()->endOfDay()])
            ->where('status', '!=', 'cancelled')
            ->get()
            ->groupBy(fn (RosterAssignment $r) => $r->work_date->toDateString());

        $this->newLine();
        $this->line("{$employee->name} (PIN {$employee->pin_device}) — {$dari->toDateString()} s/d {$sampai->toDateString()}");
        $this->newLine();

        $baris = [];
        $liburAdaScan = [];

        for ($d = $dari->copy(); $d->lessThanOrEqualTo($sampai); $d->addDay()) {
            $tgl = $d->toDateString();
            $rencana = $jadwal->get($tgl, collect());

            $labelJadwal = $rencana->isEmpty()
                ? '(belum ada jadwal)'
                : $rencana->map(fn (RosterAssignment $r) => $r->shift?->name ?? 'LIBUR')->implode(' + ');

            $scan = $logs->get($tgl, collect())
                ->map(fn (AttendanceLog $l) => $l->scanned_at->copy()->setTimezone($timezone)->format('H:i:s'));

            $libur = $rencana->isNotEmpty() && $rencana->every(fn (RosterAssignment $r) => $r->shift_id === null);

            if (($libur || $rencana->isEmpty()) && $scan->isNotEmpty()) {
                $liburAdaScan[] = $tgl;
            }

            $baris[] = [
                $d->translatedFormat('D d M'),
                $labelJadwal,
                $scan->isEmpty() ? '—' : $scan->implode('  '),
                ($libur || $rencana->isEmpty()) && $scan->isNotEmpty() ? '<-- ada scan di hari libur' : '',
            ];
        }

        $this->table(['Tanggal', 'Jadwal', 'Scan', ''], $baris);

        if ($liburAdaScan !== []) {
            $this->warn('Ada scan di hari yang jadwalnya libur/kosong: '.implode(', ', $liburAdaScan));
            $this->line('Kemungkinan jadwalnya yang tergeser, bukan orangnya yang tidak masuk.');
        }

        $this->line('Jam scan di sekitar tengah malam (00:00–03:30) milik shift hari SEBELUMNYA.');

        return self::SUCCESS;
    }

    /**
     * Semua karyawan pada SATU tanggal: siapa terjadwal apa, dan semua scan
     * mentahnya. Untuk pertanyaan "kenapa si A tidak ada di tabel absensi" —
     * jawabannya hampir selalu terlihat di sini (tidak ada scan, atau scannya
     * jatuh di luar jendela shift).
     */
    protected function semuaOrang(): int
    {
        if (! $this->option('from')) {
            $this->error('Isi --from (satu tanggal), mis. attendance:scan --from=2026-10-27');

            return self::FAILURE;
        }

        $tanggal = DateInput::parseOrFail((string) $this->option('from'), 'from');
        $timezone = config('attendance.timezone', 'Asia/Jakarta');

        $baris = [];

        foreach (Employee::query()->tracked()->employed()->orderBy('name')->get() as $e) {
            $rencana = RosterAssignment::query()
                ->with('shift')
                ->where('employee_id', $e->id)
                ->whereDate('work_date', $tanggal)
                ->where('status', '!=', 'cancelled')
                ->get()
                ->map(fn (RosterAssignment $r) => $r->shift
                    ? $r->shift->name.' '.substr((string) $r->mulaiEfektif(), 0, 5)
                    : 'LIBUR')
                ->implode(' + ');

            $scan = AttendanceLog::query()
                ->where('pin', (string) $e->pin_device)
                ->whereBetween('scanned_at', [
                    $tanggal->copy()->setTimezone($timezone)->startOfDay(),
                    $tanggal->copy()->setTimezone($timezone)->endOfDay(),
                ])
                ->orderBy('scanned_at')
                ->get()
                ->map(fn (AttendanceLog $l) => $l->scanned_at->copy()->setTimezone($timezone)->format('H:i'))
                ->implode('  ');

            $baris[] = [$e->pin_device, $e->name, $rencana !== '' ? $rencana : '(belum ada jadwal)', $scan !== '' ? $scan : '—'];
        }

        $this->newLine();
        $this->line('Semua karyawan — '.$tanggal->translatedFormat('l, d F Y'));
        $this->table(['PIN', 'Nama', 'Jadwal', 'Scan hari ini'], $baris);
        $this->line('Scan sebelum jendela shift dibuka (shift malam: 10:00) tidak dihitung ke shift itu.');

        return self::SUCCESS;
    }
}
