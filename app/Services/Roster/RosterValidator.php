<?php

namespace App\Services\Roster;

use App\Enums\AssignmentStatus;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\StaffingRequirement;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Pemeriksa kewajaran roster, berjenjang.
 *
 *   ERROR   -> memblokir. Hal yang mustahil atau melanggar keputusan yang
 *              sudah diambil: bentrok jam, ditugaskan saat cuti disetujui.
 *   WARNING -> boleh lanjut, tapi manager harus sadar. Kekurangan tenaga,
 *              jeda antar shift terlalu pendek, kerja beruntun.
 *   INFO    -> sekadar diberitahu.
 *
 * Pembagian ini bukan selera. Dengan 18 karyawan sementara kebutuhan 21-22,
 * roster yang memenuhi semua kebutuhan minimum SEKALIGUS memberi libur
 * mingguan tidak akan pernah ada. Kalau kekurangan tenaga dibuat memblokir,
 * roster tidak akan pernah bisa dipublish sama sekali. Sistem yang memaksakan
 * aturan yang tidak bisa dipenuhi akan ditinggalkan penggunanya.
 */
class RosterValidator
{
    /**
     * @return array{errors: Collection, warnings: Collection, infos: Collection}
     */
    public function validate(Roster $roster): array
    {
        $errors = collect();
        $warnings = collect();
        $infos = collect();

        $assignments = $roster->assignments()->with(['shift', 'employee', 'division'])->get();

        $this->checkOverlap($assignments, $errors);
        $this->checkStaffing($roster, $assignments, $warnings);
        $this->checkRestHours($assignments, $warnings);
        $this->checkConsecutiveDays($assignments, $warnings);
        $this->checkWeeklyOff($roster, $assignments, $warnings);
        $this->checkDoubleShift($assignments, $warnings);

        return ['errors' => $errors, 'warnings' => $warnings, 'infos' => $infos];
    }

    /** Satu orang tidak boleh punya dua shift yang jamnya bertabrakan. */
    protected function checkOverlap(Collection $assignments, Collection $errors): void
    {
        $assignments
            ->filter(fn (RosterAssignment $a) => $a->status->isWorking())
            ->groupBy('employee_id')
            ->each(function (Collection $rows, $employeeId) use ($errors) {
                $sorted = $rows->sortBy(fn ($a) => $a->startsAt()?->timestamp ?? 0)->values();

                for ($i = 1; $i < $sorted->count(); $i++) {
                    $prev = $sorted[$i - 1];
                    $curr = $sorted[$i];

                    if ($prev->endsAt() === null || $curr->startsAt() === null) {
                        continue;
                    }

                    if ($curr->startsAt()->lessThan($prev->endsAt())) {
                        $errors->push([
                            'type' => 'overlap',
                            'employee' => $prev->employee?->name,
                            'date' => $curr->work_date->toDateString(),
                            'message' => "{$prev->employee?->name} punya dua shift yang bertabrakan pada "
                                . $curr->work_date->translatedFormat('d M'),
                        ]);
                    }
                }
            });
    }

    /** Kebutuhan minimum per shift per divisi (BR-11 & BR-12). */
    protected function checkStaffing(Roster $roster, Collection $assignments, Collection $warnings): void
    {
        $requirements = StaffingRequirement::query()->with(['shift', 'division'])->get();

        if ($requirements->isEmpty()) {
            return;
        }

        $working = $assignments->filter(fn (RosterAssignment $a) => $a->status->isWorking());

        for ($date = $roster->startDate(); $date->lessThanOrEqualTo($roster->endDate()); $date->addDay()) {
            $onDate = $working->filter(fn ($a) => $a->work_date->isSameDay($date));

            foreach ($requirements as $req) {
                if (! $this->requirementApplies($req, $date)) {
                    continue;
                }

                $count = $onDate
                    ->where('shift_id', $req->shift_id)
                    ->where('division_id', $req->division_id)
                    ->count();

                if ($count < $req->required_count) {
                    $warnings->push([
                        'type' => 'understaffed',
                        'date' => $date->toDateString(),
                        'message' => $date->translatedFormat('d M') . ": {$req->shift?->name} kurang "
                            . ($req->required_count - $count) . " {$req->division?->name} "
                            . "(ada {$count}, butuh {$req->required_count})",
                    ]);
                }
            }
        }
    }

    protected function requirementApplies(StaffingRequirement $req, Carbon $date): bool
    {
        return match ($req->day_type) {
            'weekday' => ! $date->isWeekend(),
            'weekend' => $date->isWeekend(),
            default => true,
        };
    }

    /**
     * Jeda antar shift.
     *
     * Shift malam selesai 01:00, shift pagi mulai 09:00 — cuma 8 jam termasuk
     * perjalanan pulang-pergi. Di kafe itu berarti orang mengantuk memegang
     * alat panas.
     */
    protected function checkRestHours(Collection $assignments, Collection $warnings): void
    {
        $minRest = Settings::int('roster.min_rest_hours', 10);

        $assignments
            ->filter(fn (RosterAssignment $a) => $a->status->isWorking())
            ->groupBy('employee_id')
            ->each(function (Collection $rows) use ($minRest, $warnings) {
                $sorted = $rows->sortBy(fn ($a) => $a->startsAt()?->timestamp ?? 0)->values();

                for ($i = 1; $i < $sorted->count(); $i++) {
                    $prevEnd = $sorted[$i - 1]->endsAt();
                    $currStart = $sorted[$i]->startsAt();

                    if ($prevEnd === null || $currStart === null || $currStart->lessThan($prevEnd)) {
                        continue;
                    }

                    $rest = $prevEnd->diffInHours($currStart);

                    if ($rest < $minRest) {
                        $warnings->push([
                            'type' => 'short_rest',
                            'employee' => $sorted[$i]->employee?->name,
                            'date' => $sorted[$i]->work_date->toDateString(),
                            'message' => "{$sorted[$i]->employee?->name} cuma istirahat "
                                . round($rest) . " jam sebelum shift "
                                . $sorted[$i]->work_date->translatedFormat('d M')
                                . " (minimal {$minRest} jam)",
                        ]);
                    }
                }
            });
    }

    protected function checkConsecutiveDays(Collection $assignments, Collection $warnings): void
    {
        $max = Settings::int('roster.max_consecutive_days', 6);

        $assignments
            ->filter(fn (RosterAssignment $a) => $a->status->isWorking())
            ->groupBy('employee_id')
            ->each(function (Collection $rows) use ($max, $warnings) {
                $dates = $rows->pluck('work_date')
                    ->map(fn (Carbon $d) => $d->toDateString())
                    ->unique()
                    ->sort()
                    ->values();

                $streak = 0;
                $previous = null;
                $streakStart = null;

                foreach ($dates as $dateString) {
                    $date = Carbon::parse($dateString);

                    if ($previous !== null && $previous->copy()->addDay()->isSameDay($date)) {
                        $streak++;
                    } else {
                        $streak = 1;
                        $streakStart = $date->copy();
                    }

                    if ($streak === $max + 1) {
                        $warnings->push([
                            'type' => 'too_many_consecutive',
                            'employee' => $rows->first()->employee?->name,
                            'date' => $dateString,
                            'message' => "{$rows->first()->employee?->name} kerja {$streak} hari beruntun sejak "
                                . $streakStart->translatedFormat('d M') . " (batas {$max} hari)",
                        ]);
                    }

                    $previous = $date;
                }
            });
    }

    /**
     * Target libur mingguan (D-03).
     *
     * Hanya peringatan. Dengan headcount sekarang ini pasti sering tidak
     * terpenuhi — dan justru itu gunanya: kekurangannya jadi terlihat sebagai
     * data untuk memutuskan rekrutmen, bukan tersembunyi di kepala manager.
     */
    protected function checkWeeklyOff(Roster $roster, Collection $assignments, Collection $warnings): void
    {
        $target = Settings::int('roster.target_off_days_per_week', 1);

        if ($target <= 0) {
            return;
        }

        $assignments->groupBy('employee_id')->each(function (Collection $rows) use ($target, $warnings) {
            $byWeek = $rows->groupBy(fn (RosterAssignment $a) => $a->work_date->weekOfYear);

            foreach ($byWeek as $week => $weekRows) {
                // Minggu yang tidak utuh di ujung bulan tidak dihitung, kalau
                // tidak setiap roster akan selalu memunculkan peringatan palsu
                // di awal dan akhir bulan.
                if ($weekRows->count() < 7) {
                    continue;
                }

                $offDays = $weekRows->filter(fn ($a) => ! $a->status->isWorking())->count();

                if ($offDays < $target) {
                    $warnings->push([
                        'type' => 'no_weekly_off',
                        'employee' => $weekRows->first()->employee?->name,
                        'date' => $weekRows->first()->work_date->toDateString(),
                        'message' => "{$weekRows->first()->employee?->name} tidak dapat libur di minggu ke-{$week}",
                    ]);
                }
            }
        });
    }

    protected function checkDoubleShift(Collection $assignments, Collection $warnings): void
    {
        if (! Settings::bool('roster.warn_double_shift', true)) {
            return;
        }

        $assignments
            ->filter(fn (RosterAssignment $a) => $a->status->isWorking())
            ->groupBy(fn (RosterAssignment $a) => $a->employee_id . '|' . $a->work_date->toDateString())
            ->filter(fn (Collection $rows) => $rows->count() > 1)
            ->each(function (Collection $rows) use ($warnings) {
                $warnings->push([
                    'type' => 'double_shift',
                    'employee' => $rows->first()->employee?->name,
                    'date' => $rows->first()->work_date->toDateString(),
                    'message' => "{$rows->first()->employee?->name} mengambil {$rows->count()} shift pada "
                        . $rows->first()->work_date->translatedFormat('d M') . ' (16 jam kerja)',
                ]);
            });
    }
}
