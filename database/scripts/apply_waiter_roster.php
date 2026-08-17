<?php

/**
 * Skrip Mandiri: Terapkan Jadwal Rotasi 4-Mingguan Waiters
 *
 * Jalankan di server:
 *   php database/scripts/apply_waiter_roster.php
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\Division;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\Attendance\AttendanceComputer;
use App\Services\Roster\RosterService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;

echo "=== MEMULAI PENERAPAN JADWAL WAITERS ===\n";

// 1. Shift Middle
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

// 2. Karyawan
$karyawan = [
    'farrel' => Employee::where('pin_device', '3')->orWhere('name', 'Farrel Daffa')->firstOrFail(),
    'dava' => Employee::where('pin_device', '2')->orWhere('name', 'Dava Erik Prasetiyo')->firstOrFail(),
    'nur' => Employee::where('pin_device', '8')->orWhere('name', 'Nurdiansyah')->firstOrFail(),
    'amal' => Employee::where('pin_device', '6')->orWhere('name', 'Muhammad Julian Ikhlusul Amal')->firstOrFail(),
];

$waiterDiv = Division::where('code', 'waiter')->first();
if ($waiterDiv !== null) {
    foreach ($karyawan as $emp) {
        $emp->divisions()->syncWithoutDetaching([
            $waiterDiv->id => ['is_primary' => false, 'competency_level' => 3],
        ]);
    }
}

// 3. Matriks 28 Hari Rotasi
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

$rosterService = app(RosterService::class);
$computer = app(AttendanceComputer::class);

$from = Carbon::parse('2026-08-17')->startOfDay();
$to = Carbon::parse('2026-09-30')->startOfDay();
$anchor = Carbon::parse('2026-08-17')->startOfDay();

$count = 0;
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

        $count++;
    }
}

echo "Berhasil mengisi {$count} baris roster waiters (17 Agt - 30 Sep 2026).\n";

// Recompute absensi untuk tanggal yang sudah lewat (17 & 18 Agustus 2026)
echo "Menghitung ulang absensi 17-18 Agustus 2026...\n";
$computer->computeDate(Carbon::parse('2026-08-17'));
$computer->computeDate(Carbon::parse('2026-08-18'));
echo "Selesai!\n";
