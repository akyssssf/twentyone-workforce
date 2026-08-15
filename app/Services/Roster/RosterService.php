<?php

namespace App\Services\Roster;

use App\Enums\AssignmentStatus;
use App\Enums\RosterStatus;
use App\Models\Branch;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pembuatan dan penerbitan roster bulanan.
 *
 * Roster adalah sumber kebenaran tunggal untuk "hari ini dia shift apa".
 * employees.default_shift_id dan preferred_off_days hanya jadi titik awal
 * generator di kelas ini — begitu roster ada, keduanya tidak pernah lagi
 * ditanya soal jadwal.
 */
class RosterService
{
    public function __construct(
        protected RosterValidator $validator,
    ) {}

    public function findOrCreate(int $year, int $month): Roster
    {
        return Roster::firstOrCreate(
            [
                'branch_id' => Branch::current()->id,
                'period_year' => $year,
                'period_month' => $month,
            ],
            ['status' => RosterStatus::Draft],
        );
    }

    /**
     * Isi roster dengan jadwal awal.
     *
     * Bukan penjadwal cerdas — ini titik awal yang masuk akal supaya manager
     * mengedit puluhan sel, bukan mengisi 558 sel dari nol. Aturannya
     * sederhana dan bisa ditebak:
     *
     *   - pakai shift preferensi karyawan
     *   - hormati preferensi libur mingguannya
     *   - tandai tanggal merah sebagai libur
     *
     * Hasilnya PASTI melanggar kebutuhan minimum di beberapa hari, karena
     * dengan 18 orang memang tidak cukup. Itu disengaja: validator yang akan
     * menunjukkan di mana kekurangannya, bukan generator yang diam-diam
     * memaksakan angka.
     *
     * @return array{created: int, skipped: int}
     */
    public function generate(Roster $roster, bool $overwrite = false): array
    {
        $this->ensureEditable($roster);

        $employees = Employee::query()->tracked()->with('divisions')->get();
        $start = $roster->startDate();
        $end = $roster->endDate();

        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($roster, $employees, $start, $end, $overwrite, &$created, &$skipped) {
            for ($date = $start->copy(); $date->lessThanOrEqualTo($end); $date->addDay()) {
                $holiday = Holiday::closingOn($date);

                foreach ($employees as $employee) {
                    $existing = RosterAssignment::query()
                        ->where('employee_id', $employee->id)
                        ->whereDate('work_date', $date)
                        ->first();

                    if ($existing !== null && ! $overwrite) {
                        $skipped++;

                        continue;
                    }

                    // Jangan menjadwalkan orang sebelum tanggal bergabung.
                    if ($employee->joined_at !== null && $date->lessThan($employee->joined_at)) {
                        continue;
                    }

                    $libur = $holiday !== null || $employee->isOffDay($date);

                    $payload = [
                        'roster_id' => $roster->id,
                        'employee_id' => $employee->id,
                        'work_date' => $date->toDateString(),
                        'shift_id' => $libur ? null : $employee->default_shift_id,
                        'division_id' => $employee->primaryDivision()?->id,
                        'status' => $libur
                            ? ($holiday ? AssignmentStatus::Holiday : AssignmentStatus::Off)
                            : AssignmentStatus::Scheduled,
                        'source' => 'generated',
                    ];

                    if ($existing !== null) {
                        $existing->update($payload);
                    } else {
                        RosterAssignment::create($payload);
                    }

                    $created++;
                }
            }
        });

        AuditLogger::record('roster.generated', $roster, [], [
            'created' => $created,
            'skipped' => $skipped,
        ]);

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Ubah satu sel jadwal.
     *
     * shiftId null berarti libur. divisionId null dibiarkan mengikuti divisi
     * utama karyawan.
     */
    public function assign(
        Roster $roster,
        Employee $employee,
        Carbon $date,
        ?int $shiftId,
        ?int $divisionId = null,
        string $source = 'manual',
        ?int $sourceRequestId = null,
    ): RosterAssignment {
        $this->ensureEditable($roster);

        $status = $shiftId === null
            ? AssignmentStatus::Off
            : AssignmentStatus::Scheduled;

        $shiftKey = (int) ($shiftId ?? 0);

        $assignment = RosterAssignment::updateOrCreate(
            [
                'employee_id' => $employee->id,

                // Harus Carbon, bukan string "Y-m-d". Kolomnya tersimpan
                // sebagai "2026-08-15 00:00:00", jadi mencarinya dengan string
                // pendek tidak pernah ketemu dan updateOrCreate berubah jadi
                // insert yang menabrak unique constraint. Jebakan yang sama
                // sudah pernah kena di AttendanceComputer.
                'work_date' => $date->copy()->startOfDay(),

                'shift_key' => $shiftKey,
            ],
            [
                'roster_id' => $roster->id,
                'shift_id' => $shiftId,
                'division_id' => $divisionId ?? $employee->primaryDivision()?->id,
                'status' => $status,
                'source' => $source,
                'source_request_id' => $sourceRequestId,
            ],
        );

        // Memindahkan shift harus MEMINDAHKAN, bukan menambah.
        //
        // shift_key ikut jadi kunci karena double shift diizinkan, jadi
        // mengubah shift seseorang dari pagi ke malam tidak menimpa baris
        // lamanya — baris pagi tetap tinggal dan orang itu mendadak punya dua
        // jadwal di hari yang sama. Dampaknya bukan cuma tampilan: absensi
        // membuat satu rekap per assignment, jadi orangnya muncul dua kali di
        // laporan dengan telat yang dihitung dari dua jam masuk berbeda.
        //
        // Baris dari pengajuan yang sudah disetujui dilewati: cuti dan tukar
        // shift bukan sisa jadwal lama, dan menghapusnya diam-diam akan
        // membatalkan keputusan manager tanpa jejak.
        RosterAssignment::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->where('shift_key', '!=', $shiftKey)
            ->whereNotIn('source', ['leave', 'swap'])
            ->delete();

        AuditLogger::record('roster.assignment_changed', $assignment, [], [
            'employee' => $employee->name,
            'date' => $date->toDateString(),
            'shift_id' => $shiftId,
        ]);

        return $assignment;
    }

    /** Tandai satu hari sebagai cuti/izin. Dipanggil saat pengajuan disetujui. */
    public function markLeave(Employee $employee, Carbon $date, int $requestId): void
    {
        $roster = $this->findOrCreate((int) $date->year, (int) $date->month);

        RosterAssignment::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->update([
                'status' => AssignmentStatus::Leave->value,
                'shift_id' => null,
                'shift_key' => 0,
                'source' => 'leave',
                'source_request_id' => $requestId,
            ]);

        // Kalau tanggal itu belum punya baris sama sekali, buatkan — supaya
        // "sedang cuti" tetap terekam walau rosternya belum digenerate.
        $exists = RosterAssignment::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->exists();

        if (! $exists) {
            RosterAssignment::create([
                'roster_id' => $roster->id,
                'employee_id' => $employee->id,
                'work_date' => $date->toDateString(),
                'shift_id' => null,
                'division_id' => $employee->primaryDivision()?->id,
                'status' => AssignmentStatus::Leave,
                'source' => 'leave',
                'source_request_id' => $requestId,
            ]);
        }
    }

    public function publish(Roster $roster): array
    {
        $issues = $this->validator->validate($roster);

        // Error memblokir, warning tidak. Dengan 18 orang, roster yang
        // memenuhi semua kebutuhan minimum sekaligus libur mingguan penuh
        // secara matematis mustahil — memblokirnya berarti roster tidak akan
        // pernah bisa terbit dan manager kembali ke Excel.
        if ($issues['errors']->isNotEmpty()) {
            return ['published' => false, 'issues' => $issues];
        }

        $roster->update([
            'status' => RosterStatus::Published,
            'published_at' => now(),
            'published_by' => auth()->id(),
        ]);

        AuditLogger::record('roster.published', $roster, [], [
            'period' => $roster->label(),
            'warnings' => $issues['warnings']->count(),
        ]);

        return ['published' => true, 'issues' => $issues];
    }

    protected function ensureEditable(Roster $roster): void
    {
        if (! $roster->status->isEditable()) {
            throw new \RuntimeException('Roster ini sudah terkunci dan tidak bisa diubah.');
        }
    }

    /**
     * Grid roster: baris karyawan, kolom tanggal.
     *
     * @return array<string, mixed>
     */
    public function grid(Roster $roster): array
    {
        $assignments = $roster->assignments()
            ->with(['shift', 'division'])
            ->get()
            ->groupBy(fn (RosterAssignment $a) => $a->employee_id . '|' . $a->work_date->toDateString());

        // Grid roster hanya berisi orang yang memang dijadwalkan. Admin tidak
        // muncul di sini sama sekali, bukan muncul dengan baris kosong —
        // baris kosong akan terbaca seperti jadwal yang lupa diisi.
        $employees = Employee::query()
            ->tracked()
            ->with('divisions')
            ->orderBy('name')
            ->get();

        $dates = [];
        for ($d = $roster->startDate(); $d->lessThanOrEqualTo($roster->endDate()); $d->addDay()) {
            $dates[] = $d->copy();
        }

        return [
            'employees' => $employees,
            'dates' => $dates,
            'assignments' => $assignments,
            'divisions' => Division::query()->active()->orderBy('sort_order')->get(),
        ];
    }
}
