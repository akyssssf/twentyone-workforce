<?php

namespace App\Services\Attendance;

use App\Enums\AssignmentStatus;
use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\AttendanceAdjustment;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\RosterAssignment;
use App\Models\Shift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Pengubah scan mentah jadi Final Attendance.
 *
 * Urutan pipeline TIDAK BOLEH ditukar:
 *
 *   1. ambil scan di dalam jendela kerja (jendelanya berasal dari roster)
 *   2. tentukan check_in / check_out
 *   3. hitung telat, pulang cepat, durasi kerja
 *   4. tentukan status dari roster
 *   5. TERAPKAN koreksi yang sudah di-approve   <- selalu paling akhir
 *   6. tulis ke attendances
 *
 * Langkah 5 harus paling akhir. Kalau koreksi diterapkan di tengah,
 * perhitungan telat di langkah 3 memakai jam yang salah.
 *
 * Hasilnya tetap sepenuhnya turunan: tabel attendances boleh dikosongkan dan
 * dibangun ulang kapan saja tanpa kehilangan apa pun — TERMASUK keputusan
 * manager, karena koreksi hidup di tabelnya sendiri sebagai INPUT, bukan
 * sebagai hasil yang bisa tertimpa.
 *
 * Tidak ada satu pun nominal rupiah di kelas ini. Absensi mencatat fakta;
 * uang dihitung sekali saat payroll digenerate lalu dibekukan di slip.
 */
class AttendanceComputer
{
    public function __construct(
        protected OvertimeResolver $overtimeResolver,
    ) {}

    /**
     * Hitung semua karyawan aktif untuk satu tanggal.
     *
     * @return array<string, int>
     */
    public function computeDate(Carbon $workDate): array
    {
        $ringkasan = ['computed' => 0];

        foreach (AttendanceStatus::cases() as $case) {
            $ringkasan[$case->value] = 0;
        }

        $employees = Employee::query()->tracked()->employed()->with('defaultShift')->get();

        foreach ($employees as $employee) {
            foreach ($this->computeEmployee($employee, $workDate) as $attendance) {
                $ringkasan['computed']++;
                $ringkasan[$attendance->status->value]++;
            }
        }

        return $ringkasan;
    }

    /**
     * Hitung satu karyawan untuk satu tanggal.
     *
     * Mengembalikan BANYAK baris, bukan satu, karena double shift diizinkan:
     * satu orang bisa punya assignment pagi dan malam di tanggal yang sama.
     *
     * @return Collection<int, Attendance>
     */
    public function computeEmployee(Employee $employee, Carbon $workDate): Collection
    {
        $timezone = config('attendance.timezone', 'Asia/Jakarta');
        $date = $workDate->copy()->setTimezone($timezone)->startOfDay();

        // Admin punya akun dan tetap pegawai aktif, tapi tidak menempel jari
        // di mesin. Tanpa penjagaan ini mereka jadi Alpha setiap hari.
        if (! $employee->is_active || ! $employee->tracks_attendance) {
            return collect();
        }

        // Jangan bikin catatan untuk tanggal sebelum karyawan bergabung.
        if ($employee->joined_at !== null && $date->lessThan($employee->joined_at->copy()->startOfDay())) {
            return collect();
        }

        return $this->assignmentsFor($employee, $date)
            ->map(fn (?RosterAssignment $assignment) => $this->computeAssignment($employee, $date, $assignment))
            ->filter()
            ->values();
    }

    /**
     * Jadwal karyawan pada satu tanggal.
     *
     * Kalau roster bulan itu belum dibuat, mundur ke shift preferensi supaya
     * sistem tetap terpakai selama masa peralihan. Jalur cadangan ini dicabut
     * setelah roster benar-benar dipakai sehari-hari.
     *
     * @return Collection<int, ?RosterAssignment>
     */
    protected function assignmentsFor(Employee $employee, Carbon $date): Collection
    {
        $assignments = RosterAssignment::query()
            ->with(['shift', 'division'])
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->where('status', '!=', AssignmentStatus::Cancelled->value)
            ->get();

        return $assignments->isNotEmpty() ? $assignments : collect([null]);
    }

    protected function computeAssignment(Employee $employee, Carbon $date, ?RosterAssignment $assignment): ?Attendance
    {
        /** @var ?Shift $shift */
        $shift = $assignment?->shift ?? $employee->defaultShift;

        // Tidak punya shift sama sekali: bukan urusan hari ini, dan itu berbeda
        // dari alpha. Alpha berarti seharusnya masuk tapi tidak ada scan.
        if ($shift === null) {
            return null;
        }

        $window = WorkWindow::for($shift, $date);
        $timezone = config('attendance.timezone', 'Asia/Jakarta');
        $logs = $this->logsIn($employee, $window);

        // Hari yang jendelanya belum selesai belum bisa disimpulkan. Tanpa
        // penjagaan ini, menghitung hari berjalan di pagi hari akan menandai
        // semua orang alpha padahal mereka baru mau datang.
        if (Carbon::now($timezone)->lessThan($window->end) && $logs->isEmpty()) {
            return null;
        }

        [$checkIn, $checkOut, $firstLog, $lastLog] = $this->resolveCheckInOut($logs);

        $libur = $this->isNonWorkingDay($employee, $date, $assignment);

        // --- Langkah 5: koreksi manusia, diterapkan paling akhir ---
        $adjustments = AttendanceAdjustment::query()
            ->effectiveFor($employee->id, $date, (int) $shift->id)
            ->get();

        $statusOverride = null;
        $waiveLate = false;
        $waiveEarly = false;

        foreach ($adjustments as $adjustment) {
            match ($adjustment->type) {
                'set_check_in' => $checkIn = $adjustment->value_time,
                'set_check_out' => $checkOut = $adjustment->value_time,
                'set_status' => $statusOverride = $adjustment->value_status,
                'waive_late' => $waiveLate = true,
                'waive_early_leave' => $waiveEarly = true,
                default => null,
            };
        }

        $late = $this->lateness($window->scheduledIn, $checkIn, $libur || $waiveLate);
        $early = $this->earlyLeave($window->scheduledOut, $checkOut, $libur || $waiveEarly);

        $status = $statusOverride
            ? AttendanceStatus::from($statusOverride)
            : $this->resolveStatus($checkIn, $libur, $assignment);

        return Attendance::updateOrCreate(
            [
                'employee_id' => $employee->id,

                // Harus Carbon, bukan string "Y-m-d". Kolom date ini tersimpan
                // sebagai "2026-08-06 00:00:00", jadi mencarinya dengan string
                // pendek tidak pernah ketemu dan updateOrCreate berubah jadi
                // insert yang menabrak unique constraint.
                'work_date' => $date,
                'shift_key' => (int) $shift->id,
            ],
            [
                // Shift, jam jadwal, dan divisi DISALIN, bukan cuma dirujuk,
                // supaya rekap bulan lalu tidak ikut berubah kalau karyawan
                // pindah shift atau pindah divisi bulan depan.
                'shift_id' => $shift->id,
                'roster_assignment_id' => $assignment?->id,
                'division_id' => $assignment?->division_id,
                'scheduled_in' => $window->scheduledIn,
                'scheduled_out' => $window->scheduledOut,

                'check_in_at' => $checkIn,
                'check_out_at' => $checkOut,
                'first_log_id' => $firstLog?->id,
                'last_log_id' => $lastLog?->id,

                'late_seconds' => $late['seconds'],
                'late_minutes' => $late['minutes'],
                'early_leave_seconds' => $early['seconds'],
                'early_leave_minutes' => $early['minutes'],
                'work_minutes' => $this->workMinutes($checkIn, $checkOut, $shift),

                // Lembur hanya dari approval yang sudah dikonfirmasi. Menit
                // setelah jam pulang tanpa approval BUKAN lembur (BR-14).
                'overtime_minutes' => $this->overtimeResolver->minutesFor($employee, $date),

                'has_adjustment' => $adjustments->isNotEmpty(),
                'source_note' => $adjustments->isNotEmpty()
                    ? $adjustments->pluck('type')->unique()->implode(', ')
                    : null,

                'status' => $status,
                'computed_at' => Carbon::now(),
            ],
        );
    }

    /**
     * Scan milik karyawan ini di dalam jendela kerja.
     *
     * Dijodohkan lewat employee_id — hasil pemetaan employee_devices yang
     * berlaku pada TANGGAL SCAN, bukan lewat PIN yang dipegang hari ini.
     * Inilah yang mencegah riwayat absensi berpindah orang saat PIN mesin
     * dipakai ulang karyawan baru.
     *
     * @return Collection<int, AttendanceLog>
     */
    protected function logsIn(Employee $employee, WorkWindow $window): Collection
    {
        return AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('scanned_at', [$window->start, $window->end])
            ->orderBy('scanned_at')
            ->get();
    }

    /**
     * @param  Collection<int, AttendanceLog>  $logs
     * @return array{0: ?Carbon, 1: ?Carbon, 2: ?AttendanceLog, 3: ?AttendanceLog}
     */
    protected function resolveCheckInOut(Collection $logs): array
    {
        if ($logs->isEmpty()) {
            return [null, null, null, null];
        }

        if (config('attendance.check_in_out_strategy') === 'status_scan') {
            $masuk = $logs->firstWhere('status_scan', 0);
            $keluar = $logs->last(fn ($log) => $log->status_scan === 1);

            // Kalau tombol fungsinya ternyata tidak dipakai, jangan kembalikan
            // kosong. Lebih baik mundur ke cara paling aman daripada menuduh
            // alpha orang yang sebenarnya masuk.
            if ($masuk !== null) {
                return [$masuk->scanned_at, $keluar?->scanned_at, $masuk, $keluar];
            }
        }

        $first = $logs->first();
        $last = $logs->count() > 1 ? $logs->last() : null;

        return [$first->scanned_at, $last?->scanned_at, $first, $last];
    }

    /**
     * @return array{seconds: int, minutes: int}
     */
    protected function lateness(Carbon $scheduledIn, ?Carbon $checkIn, bool $waived): array
    {
        if ($waived || $checkIn === null) {
            return ['seconds' => 0, 'minutes' => 0];
        }

        $selisih = $checkIn->getTimestamp() - $scheduledIn->getTimestamp();

        if ($selisih <= 0) {
            return ['seconds' => 0, 'minutes' => 0];
        }

        // Toleransi nol (BR-04): lewat satu detik pun sudah telat. Menitnya
        // dibulatkan KE ATAS karena tier potongan berbicara dalam menit —
        // telat 1 detik jatuh ke tier "1-10 menit", bukan ke nol.
        return ['seconds' => $selisih, 'minutes' => (int) ceil($selisih / 60)];
    }

    /**
     * @return array{seconds: int, minutes: int}
     */
    protected function earlyLeave(Carbon $scheduledOut, ?Carbon $checkOut, bool $waived): array
    {
        // Tidak ada scan pulang BUKAN berarti pulang cepat. Itu kasus lupa
        // fingerprint, dan jalurnya koreksi absensi — bukan potongan.
        if ($waived || $checkOut === null) {
            return ['seconds' => 0, 'minutes' => 0];
        }

        $selisih = $scheduledOut->getTimestamp() - $checkOut->getTimestamp();

        if ($selisih <= 0) {
            return ['seconds' => 0, 'minutes' => 0];
        }

        return ['seconds' => $selisih, 'minutes' => (int) ceil($selisih / 60)];
    }

    protected function workMinutes(?Carbon $checkIn, ?Carbon $checkOut, Shift $shift): int
    {
        if ($checkIn === null || $checkOut === null) {
            return 0;
        }

        $minutes = (int) $checkIn->diffInMinutes($checkOut);

        // Istirahat dibayar (D-02), jadi tidak dipotong dari jam kerja. Kalau
        // kebijakannya berubah, cukup ubah is_break_paid di master shift —
        // tidak ada kode yang perlu disentuh.
        return $shift->is_break_paid ? $minutes : max(0, $minutes - (int) $shift->break_minutes);
    }

    protected function isNonWorkingDay(Employee $employee, Carbon $date, ?RosterAssignment $assignment): bool
    {
        if ($assignment !== null) {
            return ! $assignment->status->isWorking();
        }

        // Tanpa roster: mundur ke preferensi libur + libur bersama.
        return $employee->isOffDay($date) || Holiday::closingOn($date) !== null;
    }

    protected function resolveStatus(?Carbon $checkIn, bool $libur, ?RosterAssignment $assignment): AttendanceStatus
    {
        if (! $libur) {
            return $checkIn === null ? AttendanceStatus::Alpha : AttendanceStatus::Hadir;
        }

        // Masuk di hari libur tetap dicatat hadir — dia sedang membantu di luar
        // jadwal, bukan melanggar jadwal yang tidak ada. Ini kandidat utama
        // lembur, tapi tetap harus lewat approval, tidak otomatis.
        if ($checkIn !== null) {
            return AttendanceStatus::Hadir;
        }

        return match ($assignment?->status) {
            AssignmentStatus::Leave => AttendanceStatus::Cuti,
            default => AttendanceStatus::Libur,
        };
    }
}
