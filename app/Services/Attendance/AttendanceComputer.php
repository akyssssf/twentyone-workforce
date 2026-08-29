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

        // Batas bawah perusahaan, bukan per karyawan. Sebelum tanggal ini
        // mesin belum terpasang, jadi "tidak ada scan" bukan alpha — tidak
        // pernah ada yang bisa scan. pruneStaleAttendances dipanggil di sini
        // (bukan cuma return kosong) supaya rekap lama dari sebelum batas ini
        // ikut tersapu kalau masih ada sisa dari sebelum batas ini dipasang.
        $mulaiPelacakan = config('attendance.tracking_starts_on');

        if ($mulaiPelacakan !== null && $date->lessThan(Carbon::parse($mulaiPelacakan, $timezone)->startOfDay())) {
            $this->pruneStaleAttendances($employee, $date, collect());

            return collect();
        }

        // Admin punya akun dan tetap pegawai aktif, tapi tidak menempel jari
        // di mesin. Tanpa penjagaan ini mereka jadi Alpha setiap hari.
        if (! $employee->is_active || ! $employee->tracks_attendance) {
            return collect();
        }

        // Jangan bikin catatan untuk tanggal sebelum karyawan bergabung.
        if ($employee->joined_at !== null && $date->lessThan($employee->joined_at->copy()->startOfDay())) {
            return collect();
        }

        $hasil = collect($this->konteksHarian($employee, $date))
            ->map(fn (array $konteks) => $this->computeAssignment($employee, $date, $konteks))
            ->filter()
            ->values();

        $this->pruneStaleAttendances($employee, $date, $hasil);

        return $hasil;
    }

    /**
     * Jejak lengkap perhitungan satu karyawan pada satu tanggal — untuk
     * diagnosis, tidak menyimpan apa pun.
     *
     * Ditaruh di kelas ini, bukan di perintah artisan yang memanggilnya, supaya
     * penjelasannya memakai helper yang PERSIS SAMA dengan yang dipakai
     * perhitungan asli. Diagnosis yang punya salinan logikanya sendiri akan
     * menyimpang diam-diam dari kenyataan, dan diagnosis yang berbohong lebih
     * berbahaya daripada tidak ada diagnosis sama sekali.
     *
     * @return list<array<string, mixed>>
     */
    public function jejak(Employee $employee, Carbon $workDate): array
    {
        $timezone = config('attendance.timezone', 'Asia/Jakarta');
        $date = $workDate->copy()->setTimezone($timezone)->startOfDay();

        $hasil = [];

        foreach ($this->konteksHarian($employee, $date) as $konteks) {
            [$checkIn, $checkOut] = $this->resolveCheckInOut(
                $konteks['logs'], $konteks['window']->scheduledOut,
            );

            $hasil[] = $konteks + [
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'adjustments' => AttendanceAdjustment::query()
                    ->effectiveFor($employee->id, $date, (int) $konteks['shift']->id)
                    ->get(),
                'attendance' => Attendance::query()
                    ->where('employee_id', $employee->id)
                    ->where('work_date', $date)
                    ->where('shift_key', (int) $konteks['shift']->id)
                    ->first(),
            ];
        }

        return $hasil;
    }

    /**
     * Konteks perhitungan tiap baris jadwal pada satu tanggal: shift, jendela,
     * dan scan yang menjadi MILIKNYA.
     *
     * Dipakai bersama oleh perhitungan asli dan diagnosis, supaya keduanya tidak
     * mungkin menyimpang.
     *
     * @return list<array<string, mixed>>
     */
    protected function konteksHarian(Employee $employee, Carbon $date): array
    {
        $konteks = [];

        foreach ($this->assignmentsFor($employee, $date) as $assignment) {
            /** @var ?Shift $shift */
            $shift = $assignment?->shift ?? $this->guessShift($employee, $date);

            // Tidak punya shift sama sekali: bukan urusan hari ini, dan itu
            // berbeda dari alpha. Alpha berarti seharusnya masuk tapi tidak scan.
            if ($shift === null) {
                continue;
            }

            $konteks[] = [
                'assignment' => $assignment,
                'shift' => $shift,

                // Shift hasil tebakan tidak pernah kebagian jam khusus, karena
                // jam khusus menempel di baris roster yang justru tidak ada.
                'ditebak' => $assignment?->shift === null,

                'window' => WorkWindow::for($shift, $date, $assignment),
                'logs' => collect(),
            ];
        }

        return $this->bagikanScan($employee, $konteks);
    }

    /**
     * Bagikan scan ke baris jadwal yang paling pas — tiap scan cuma ke SATU baris.
     *
     * Sebelumnya tiap baris mencari scannya sendiri-sendiri, dan pada orang yang
     * punya dua jadwal di hari yang sama jendelanya saling tumpang tindih: satu
     * kali menempel jari jam 14:06 terbaca sebagai jam masuk untuk KEDUA shift
     * sekaligus — telat 7 menit di shift yang benar, dan telat 6 jam 7 menit di
     * shift pagi yang tidak pernah dia jalani. Telat sebesar itu adalah potongan
     * gaji atas hari yang tidak pernah terjadi, dan tidak ada error apa pun yang
     * menandainya.
     *
     * Kepemilikan diukur ke SELURUH rentang jadwal (jam masuk sampai jam pulang),
     * bukan ke jam masuknya saja. Kalau diukur ke jam masuk, scan PULANG akan
     * lari ke shift berikutnya yang jam mulainya kebetulan lebih dekat — orang
     * yang benar-benar kerja dua shift terpisah kehilangan jam pulang shift
     * pertamanya lalu terbaca telat berjam-jam di shift kedua. Itu sebabnya
     * perbaikan yang paling gampang justru salah.
     *
     * @param  list<array<string, mixed>>  $konteks
     * @return list<array<string, mixed>>
     */
    protected function bagikanScan(Employee $employee, array $konteks): array
    {
        if ($konteks === []) {
            return [];
        }

        // Satu jadwal saja: tidak ada yang perlu dibagi, dan jalurnya sengaja
        // dibiarkan persis seperti dulu — ini kasus hampir semua orang.
        if (count($konteks) === 1) {
            $konteks[0]['logs'] = $this->logsIn($employee, $konteks[0]['window']);

            return $konteks;
        }

        $mulai = $konteks[0]['window']->start->copy();
        $selesai = $konteks[0]['window']->end->copy();

        foreach ($konteks as $k) {
            $mulai = $k['window']->start->lessThan($mulai) ? $k['window']->start->copy() : $mulai;
            $selesai = $k['window']->end->greaterThan($selesai) ? $k['window']->end->copy() : $selesai;
        }

        $logs = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('scanned_at', [$mulai, $selesai])
            ->orderBy('scanned_at')
            ->get();

        foreach ($logs as $log) {
            $pilihan = null;
            $jarakTerbaik = null;
            $selisihMasukTerbaik = null;

            foreach ($konteks as $i => $k) {
                // Scan di luar jendela tetap dibuang seperti sebelumnya —
                // pembagian ini tidak melonggarkan syarat kepemilikan.
                if (! $k['window']->contains($log->scanned_at)) {
                    continue;
                }

                $jarak = $this->jarakKeJadwal($k['window'], $log->scanned_at);
                $selisihMasuk = abs($log->scanned_at->getTimestamp() - $k['window']->scheduledIn->getTimestamp());

                // Seri berarti scan itu berada di dalam rentang dua jadwal
                // sekaligus — shift yang jamnya memang tumpang tindih, yang di
                // kafe ini selalu berarti salah jadwal karena tidak ada dua
                // shift yang bisa dijalani bersamaan. Pemenangnya yang jam
                // masuknya paling dekat; yang kalah tidak dapat scan sama
                // sekali lalu jatuh ke alpha, dan itu memang yang seharusnya
                // terjadi: salah jadwalnya jadi terlihat, bukan tersamar
                // sebagai telat berjam-jam.
                $lebihBaik = $jarakTerbaik === null
                    || $jarak < $jarakTerbaik
                    || ($jarak === $jarakTerbaik && $selisihMasuk < $selisihMasukTerbaik);

                if ($lebihBaik) {
                    $jarakTerbaik = $jarak;
                    $selisihMasukTerbaik = $selisihMasuk;
                    $pilihan = $i;
                }
            }

            if ($pilihan !== null) {
                $konteks[$pilihan]['logs']->push($log);
            }
        }

        return $konteks;
    }

    /** Nol kalau scan ada di dalam rentang jadwal; selain itu jarak ke ujung terdekat, dalam detik. */
    protected function jarakKeJadwal(WorkWindow $window, Carbon $waktu): int
    {
        $t = $waktu->getTimestamp();
        $mulai = $window->scheduledIn->getTimestamp();
        $selesai = $window->scheduledOut->getTimestamp();

        if ($t >= $mulai && $t <= $selesai) {
            return 0;
        }

        return (int) min(abs($t - $mulai), abs($t - $selesai));
    }

    /**
     * Semua scan yang MUNGKIN milik satu hari kerja — termasuk yang nanti
     * dibuang jendela.
     *
     * Rentangnya HARI OPERASIONAL, bukan tanggal kalender: 06:00 hari itu
     * sampai 06:00 besoknya (ATTENDANCE_DASHBOARD_CUTOVER_HOUR, konvensi yang
     * sama dengan dashboard). Kedua batas harus ikut ambang yang sama — memakai
     * tengah malam sebagai batas bawah sementara batas atasnya 06:00 membuat
     * scan PULANG shift malam kemarin (jatuh sekitar 01:00) terhitung sebagai
     * "scan pertama hari ini". Itu bukan kesalahan teoretis: pernah membuat
     * seluruh karyawan terlapor bermasalah selama seminggu penuh.
     *
     * Diperlebar mengikuti jendela shift hari itu kalau jendelanya keluar dari
     * hari operasional — shift pagi dengan jam khusus 08:00 jendelanya sudah
     * dibuka 04:00, dan orang yang datang jam segitu tetap harus kelihatan.
     *
     * @param  list<array<string, mixed>>  $jejak  hasil jejak(), untuk batas jendela
     * @return Collection<int, AttendanceLog>
     */
    public function scanHarian(Employee $employee, Carbon $workDate, array $jejak = []): Collection
    {
        $timezone = config('attendance.timezone', 'Asia/Jakarta');
        $ambang = (int) config('attendance.dashboard_cutover_hour', 6);

        $mulai = $workDate->copy()->setTimezone($timezone)->startOfDay()->addHours($ambang);
        $selesai = $mulai->copy()->addDay();

        foreach ($jejak as $baris) {
            if (($baris['shift'] ?? null) === null) {
                continue;
            }

            /** @var WorkWindow $window */
            $window = $baris['window'];

            if ($window->start->lessThan($mulai)) {
                $mulai = $window->start->copy();
            }

            if ($window->end->greaterThan($selesai)) {
                $selesai = $window->end->copy();
            }
        }

        return AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('scanned_at', [$mulai, $selesai])
            ->orderBy('scanned_at')
            ->get();
    }

    /**
     * Buang baris attendances lama milik employee+tanggal ini yang tidak ikut
     * kebuat lagi di hitungan barusan.
     *
     * Perlu karena shift_key ikut jadi bagian primary key (double shift boleh
     * dalam satu hari), dan guessShift() bisa pindah pilihan shift dari satu
     * hitungan ke hitungan berikutnya begitu scan baru masuk lewat sync yang
     * menyusul webhook. Tanpa ini, baris hasil tebakan lama jadi anak hilang:
     * tidak pernah ketiban updateOrCreate lagi, tapi tetap nongol di laporan
     * jadi duplikat orang yang sama untuk tanggal yang sama.
     */
    protected function pruneStaleAttendances(Employee $employee, Carbon $date, Collection $fresh): void
    {
        Attendance::query()
            ->where('employee_id', $employee->id)
            ->where('work_date', $date)
            ->whereNotIn('id', $fresh->pluck('id'))
            ->delete();
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

    /** @param  array<string, mixed>  $konteks  hasil konteksHarian(): shift, jendela, dan scan miliknya */
    protected function computeAssignment(Employee $employee, Carbon $date, array $konteks): ?Attendance
    {
        /** @var ?RosterAssignment $assignment */
        $assignment = $konteks['assignment'];

        /** @var Shift $shift */
        $shift = $konteks['shift'];

        /** @var WorkWindow $window */
        $window = $konteks['window'];

        $timezone = config('attendance.timezone', 'Asia/Jakarta');

        // Scan sudah dibagikan eksklusif di konteksHarian(), jadi baris ini
        // tidak boleh mencari sendiri lagi — di situlah dulu satu scan bisa
        // diklaim dua shift sekaligus.
        $logs = $konteks['logs'];

        // Hari yang jendelanya belum selesai belum bisa disimpulkan. Tanpa
        // penjagaan ini, menghitung hari berjalan di pagi hari akan menandai
        // semua orang alpha padahal mereka baru mau datang.
        if (Carbon::now($timezone)->lessThan($window->end) && $logs->isEmpty()) {
            return null;
        }

        [$checkIn, $checkOut, $firstLog, $lastLog] = $this->resolveCheckInOut($logs, $window->scheduledOut);

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

                // Lembur hanya dari penugasan yang sudah diaktifkan kodenya.
                // Menit setelah jam pulang tanpa penugasan BUKAN lembur
                // (BR-14) — yang dihitung otomatis cuma durasinya, dari jam
                // pulang terjadwal sampai scan terakhir.
                'overtime_minutes' => $this->overtimeResolver->minutesFor(
                    $employee, $date, $shift, $window->scheduledOut,
                ),

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
     * Tanpa roster, tebak shift dari jam scan pertama hari itu, bukan
     * langsung percaya default_shift_id.
     *
     * default_shift_id gampang basi: orang kadang dapat giliran beda dari
     * biasanya sementara roster belum diisi per hari. Kalau tetap dipaksa ke
     * default lama, orang yang biasanya shift pagi tapi hari ini masuk shift
     * malam akan terbaca telat berjam-jam padahal dia datang tepat waktu
     * untuk shift yang dia jalani hari ini.
     *
     * Aturannya: ambil shift yang jam mulainya PALING DEKAT dengan scan
     * pertama, berapa pun jumlah shift aktifnya.
     *
     * Dulu ini dibatasi "persis 2 shift" karena kafe cuma buka pagi & malam.
     * Batasan itu jadi jebakan begitu shift ketiga (middle, 11:00) dibuat:
     * jumlahnya bukan 2 lagi, jadi seluruh tebakan mati diam-diam dan semua
     * orang jatuh ke default_shift_id — yang datang 13:53 untuk shift malam
     * mendadak terbaca telat 354 menit atas shift pagi yang tidak dia jalani.
     */
    protected function guessShift(Employee $employee, Carbon $date): ?Shift
    {
        $default = $employee->defaultShift;

        $aktif = Shift::active()->orderBy('start_time')->get();

        // Satu shift saja tidak ada yang bisa ditebak: pilihannya cuma itu.
        if ($aktif->count() < 2) {
            return $default;
        }

        $timezone = config('attendance.timezone', 'Asia/Jakarta');

        // Mulai pencarian beberapa jam setelah tengah malam, bukan dari
        // 00:00, supaya scan pulang shift malam kemarin (bisa jatuh sampai
        // sekitar jam 01:00-05:00 karena window_after_hours) tidak salah
        // kebaca sebagai scan masuk hari ini.
        $mulaiJam = (int) config('attendance.shift_guess.detection_start_hour', 6);

        $scanPertama = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('scanned_at', [
                $date->copy()->setTimezone($timezone)->startOfDay()->addHours($mulaiJam),
                $date->copy()->setTimezone($timezone)->endOfDay(),
            ])
            ->orderBy('scanned_at')
            ->first();

        // Tidak ada scan sama sekali: tidak ada dasar buat menebak, kembali
        // ke default supaya tetap bisa dilaporkan alpha atas shift yang benar.
        if ($scanPertama === null) {
            return $default;
        }

        $waktuScan = $scanPertama->scanned_at->copy()->setTimezone($timezone);
        $menitScan = $waktuScan->hour * 60 + $waktuScan->minute;

        return $aktif
            ->sortBy(fn (Shift $shift) => abs($this->menitMulai($shift) - $menitScan))
            ->first();
    }

    /** Jam mulai shift sebagai menit sejak tengah malam. */
    protected function menitMulai(Shift $shift): int
    {
        [$jam, $menit] = array_pad(explode(':', (string) $shift->start_time), 2, '0');

        return ((int) $jam) * 60 + (int) $menit;
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
    protected function resolveCheckInOut(Collection $logs, Carbon $scheduledOut): array
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

        // Scan pulang cuma diambil dari jendela dekat scheduled_out. Scan
        // nyasar di tengah shift jangan sampai terbaca sebagai jam pulang
        // hanya karena dia yang paling akhir hari itu.
        $captureFrom = $scheduledOut->copy()->subMinutes(
            (int) config('attendance.checkout_capture_minutes', 60)
        );

        $last = $logs->count() > 1
            ? $logs->last(fn ($log) => $log->scanned_at->greaterThanOrEqualTo($captureFrom))
            : null;

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
            if ($checkIn !== null) {
                return AttendanceStatus::Hadir;
            }

            // SEMENTARA: roster harian belum diisi untuk semua karyawan.
            // Kalau shift-nya cuma hasil tebakan dari default_shift_id (tidak
            // ada RosterAssignment asli untuk tanggal ini), jangan vonis
            // alpha — anggap libur dulu sampai rosternya benar-benar dibuat.
            // Late/pulang-cepat tidak ikut dibebaskan di sini (itu ikut
            // $libur di atas, bukan ikut cek ini) supaya karyawan yang tetap
            // datang tanpa roster masih kena aturan telat seperti biasa.
            // Cabut fallback ini begitu roster dipakai penuh untuk semua
            // karyawan.
            return $assignment === null ? AttendanceStatus::Libur : AttendanceStatus::Alpha;
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
