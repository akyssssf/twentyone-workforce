<?php

namespace App\Console\Commands;

use App\Models\Division;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\Attendance\AttendanceComputer;
use App\Services\Roster\RosterService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Terapkan jadwal rotasi 4-mingguan Waiters ke roster bulanan.
 *
 * Pola 4 mingguan dimulai dari Senin 17 Agustus 2026 (Minggu 1).
 * Melibatkan 4 orang:
 *   - Waye  : Farrel Daffa (PIN 3)
 *   - Dafa  : Dava Erik Prasetiyo (PIN 2)
 *   - Nur   : Nurdiansyah (PIN 8)
 *   - Amal  : Muhammad Julian Ikhlusul Amal (PIN 6)
 */
class ApplyWaiterRoster extends Command
{
    protected $signature = 'roster:apply-waiters
                            {--from=2026-08-17 : Tanggal awal penerapan (YYYY-MM-DD)}
                            {--to=2026-09-30 : Tanggal akhir penerapan (YYYY-MM-DD)}
                            {--recompute : Hitung ulang absensi pada rentang tanggal terkait}';

    protected $description = 'Terapkan rotasi jadwal 4-mingguan untuk tim Waiters';

    public function handle(RosterService $rosterService, AttendanceComputer $computer): int
    {
        $from = Carbon::parse($this->option('from'))->startOfDay();
        $to = Carbon::parse($this->option('to'))->startOfDay();

        if ($to->lessThan($from)) {
            $this->error('Tanggal akhir tidak boleh lebih awal dari tanggal awal.');

            return self::FAILURE;
        }

        $this->info("Menerapkan rotasi Waiters: {$from->toDateString()} s/d {$to->toDateString()}");

        // 1. Pastikan Shift Middle tersedia
        $middleShift = Shift::updateOrCreate(
            ['code' => 'middle'],
            [
                'name' => 'Shift Middle',
                'start_time' => '11:30:00',
                'end_time' => '01:00:00',
                'crosses_midnight' => true,
                'break_minutes' => 60,
                'is_break_paid' => true,
                'window_before_hours' => 4,
                'window_after_hours' => 4,
                'color' => '#10b981',
                'is_active' => true,
                'show_hours' => false,
            ]
        );

        $shifts = [
            'pagi' => Shift::where('code', 'pagi')->firstOrFail(),
            'malam' => Shift::where('code', 'malam')->firstOrFail(),
            'middle' => $middleShift,
        ];

        // 2. Ambil data 4 karyawan Waiters
        $karyawan = [
            'farrel' => Employee::where('pin_device', '3')->orWhere('name', 'Farrel Daffa')->first(),
            'dava' => Employee::where('pin_device', '2')->orWhere('name', 'Dava Erik Prasetiyo')->first(),
            'nur' => Employee::where('pin_device', '8')->orWhere('name', 'Nurdiansyah')->first(),
            'amal' => Employee::where('pin_device', '6')->orWhere('name', 'Muhammad Julian Ikhlusul Amal')->first(),
        ];

        foreach ($karyawan as $alias => $emp) {
            if ($emp === null) {
                $this->error("Karyawan untuk alias '{$alias}' tidak ditemukan di database.");

                return self::FAILURE;
            }
        }

        $waiterDiv = Division::where('code', 'waiter')->first();
        if ($waiterDiv !== null) {
            foreach ($karyawan as $emp) {
                $emp->divisions()->syncWithoutDetaching([
                    $waiterDiv->id => ['is_primary' => false, 'competency_level' => 3],
                ]);
            }
        }

        // 3. Matriks 28 Hari Rotasi Waiters (Hari 0 = Senin Minggu 1)
        $pola = [
            // MINGGU 1
            0 => ['farrel' => 'pagi',   'dava' => 'malam',  'nur' => 'malam',  'amal' => null],
            1 => ['farrel' => null,     'dava' => 'pagi',   'nur' => 'malam',  'amal' => 'malam'],
            2 => ['farrel' => 'malam',  'dava' => null,     'nur' => 'pagi',   'amal' => 'malam'],
            3 => ['farrel' => 'pagi',   'dava' => 'malam',  'nur' => null,     'amal' => 'malam'],
            4 => ['farrel' => 'malam',  'dava' => 'middle', 'nur' => 'malam',  'amal' => 'pagi'],
            5 => ['farrel' => 'middle', 'dava' => 'malam',  'nur' => 'pagi',   'amal' => 'malam'],
            6 => ['farrel' => 'pagi',   'dava' => 'malam',  'nur' => 'malam',  'amal' => 'pagi'],

            // MINGGU 2
            7 => ['farrel' => 'pagi',   'dava' => 'malam',  'nur' => 'malam',  'amal' => null],
            8 => ['farrel' => null,     'dava' => 'pagi',   'nur' => 'malam',  'amal' => 'malam'],
            9 => ['farrel' => 'malam',  'dava' => null,     'nur' => 'pagi',   'amal' => 'malam'],
            10 => ['farrel' => 'malam',  'dava' => 'malam',  'nur' => null,     'amal' => 'pagi'],
            11 => ['farrel' => 'middle', 'dava' => 'pagi',   'nur' => 'malam',  'amal' => 'malam'],
            12 => ['farrel' => 'malam',  'dava' => 'pagi',   'nur' => 'malam',  'amal' => 'middle'],
            13 => ['farrel' => 'malam',  'dava' => 'malam',  'nur' => 'pagi',   'amal' => 'pagi'],

            // MINGGU 3
            14 => ['farrel' => 'pagi',   'dava' => 'malam',  'nur' => 'malam',  'amal' => null],
            15 => ['farrel' => null,     'dava' => 'pagi',   'nur' => 'malam',  'amal' => 'malam'],
            16 => ['farrel' => 'malam',  'dava' => null,     'nur' => 'pagi',   'amal' => 'malam'],
            17 => ['farrel' => 'malam',  'dava' => 'malam',  'nur' => null,     'amal' => 'pagi'],
            18 => ['farrel' => 'pagi',   'dava' => 'malam',  'nur' => 'middle', 'amal' => 'malam'],
            19 => ['farrel' => 'pagi',   'dava' => 'malam',  'nur' => 'middle', 'amal' => 'malam'],
            20 => ['farrel' => 'pagi',   'dava' => 'malam',  'nur' => 'malam',  'amal' => 'pagi'],

            // MINGGU 4
            21 => ['farrel' => 'pagi',   'dava' => 'malam',  'nur' => 'malam',  'amal' => null],
            22 => ['farrel' => null,     'dava' => 'pagi',   'nur' => 'malam',  'amal' => 'malam'],
            23 => ['farrel' => 'malam',  'dava' => null,     'nur' => 'pagi',   'amal' => 'malam'],
            24 => ['farrel' => 'malam',  'dava' => 'malam',  'nur' => null,     'amal' => 'pagi'],
            25 => ['farrel' => 'malam',  'dava' => 'pagi',   'nur' => 'middle', 'amal' => 'malam'],
            26 => ['farrel' => 'malam',  'dava' => 'middle', 'nur' => 'malam',  'amal' => 'pagi'],
            27 => ['farrel' => 'pagi',   'dava' => 'malam',  'nur' => 'malam',  'amal' => 'pagi'],
        ];

        $anchor = Carbon::parse('2026-08-17')->startOfDay();
        $assignedCount = 0;

        for ($date = $from->copy(); $date->lessThanOrEqualTo($to); $date->addDay()) {
            $diff = (int) $anchor->diffInDays($date, false);
            $cycleDay = ($diff % 28 + 28) % 28;
            $roster = $rosterService->findOrCreate((int) $date->year, (int) $date->month);

            foreach ($karyawan as $alias => $emp) {
                $shiftCode = $pola[$cycleDay][$alias];
                $shiftId = $shiftCode !== null ? $shifts[$shiftCode]->id : null;

                $rosterService->assign(
                    $roster,
                    $emp,
                    $date,
                    $shiftId,
                    $waiterDiv?->id,
                    'manual'
                );

                $assignedCount++;
            }
        }

        $this->info("Berhasil menetapkan {$assignedCount} jadwal untuk 4 waiter.");

        if ($this->option('recompute')) {
            $this->info('Menghitung ulang absensi...');
            for ($date = $from->copy(); $date->lessThanOrEqualTo($to); $date->addDay()) {
                $computer->computeDate($date);
            }
            $this->info('Hitung ulang absensi selesai.');
        }

        return self::SUCCESS;
    }
}
