<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Request as PengajuanModel;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Support\DateInput;
use App\Support\OperationalDate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Dashboard manager.
 *
 * Sembilan angka yang diminta brief ditampilkan di sini — tapi tiga di
 * antaranya ("Terlambat", "Pulang Cepat", "Lembur") BUKAN status yang tersimpan
 * di database, melainkan hitungan dari kolom menit. Itu sebabnya seorang chef
 * bisa muncul sekaligus di kartu Hadir, Terlambat, dan Lembur: ketiganya
 * memang terjadi bersamaan pada dirinya hari itu.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $timezone = config('attendance.timezone', 'Asia/Jakarta');
        $tanggal = $this->tanggalDiminta($request, $timezone);

        $rekap = Attendance::with(['employee', 'shift', 'division', 'firstLog', 'lastLog'])
            ->whereDate('work_date', $tanggal)
            ->get()
            ->sortBy(fn (Attendance $a) => $a->employee?->name)
            ->values();

        // Jendela dilebarkan sampai akhir hari berikutnya supaya scan pulang
        // shift malam, yang jatuh lewat tengah malam, ikut tampil.
        $scan = AttendanceLog::query()
            ->with('employee')
            ->whereBetween('scanned_at', [$tanggal->copy(), $tanggal->copy()->addDay()->endOfDay()])
            ->orderByDesc('scanned_at')
            ->get();

        $jadwal = RosterAssignment::query()
            ->with(['employee', 'shift', 'division'])
            ->whereDate('work_date', $tanggal)
            ->working()
            ->get()
            ->groupBy('shift_id');

        $urutan = $request->query('urutan', 'nama');
        $kondisi = $request->query('kondisi', '');
        $shiftFilter = $request->query('shift_id', '');

        return view('dashboard.index', [
            'tanggal' => $tanggal,
            'rekap' => $rekap,
            'rekapTabel' => $this->saringDanUrutkan($rekap, $shiftFilter, $kondisi, $urutan),
            'filter' => [
                'urutan' => $urutan,
                'kondisi' => $kondisi,
                'shift_id' => $shiftFilter,
            ],
            'scan' => $scan,
            'jadwal' => $jadwal,
            'shifts' => Shift::query()->where('is_active', true)->get(),

            // PIN yang mengirim scan tapi tidak dikenali pemetaan mana pun.
            // Scan-nya tersimpan aman, tapi belum masuk rekap siapa pun.
            'pinAsing' => $scan->whereNull('employee_id')->pluck('pin')->unique()->values(),

            'ringkasan' => [
                'karyawan' => Employee::tracked()->count(),
                'hadir' => $rekap->where('status', AttendanceStatus::Hadir)->count(),
                'terlambat' => $rekap->filter(fn ($a) => $a->late_minutes > 0)->count(),
                'pulang_cepat' => $rekap->filter(fn ($a) => $a->early_leave_minutes > 0)->count(),
                'alpha' => $rekap->where('status', AttendanceStatus::Alpha)->count(),
                'izin' => $rekap->where('status', AttendanceStatus::Izin)->count(),
                'sakit' => $rekap->where('status', AttendanceStatus::Sakit)->count(),
                'cuti' => $rekap->where('status', AttendanceStatus::Cuti)->count(),
                'libur' => $rekap->where('status', AttendanceStatus::Libur)->count(),
                'lembur' => $rekap->filter(fn ($a) => $a->overtime_minutes > 0)->count(),
            ],

            'pengajuanPending' => PengajuanModel::query()
                ->with(['employee', 'leave.leaveType'])
                ->awaitingManager()
                ->latest('id')
                ->limit(10)
                ->get(),

            'jumlahPending' => PengajuanModel::query()->awaitingManager()->count(),
            'menungguPengganti' => PengajuanModel::query()->where('status', 'pending_peer')->count(),

            // Tren tujuh hari. Angka satu hari tidak memberi tahu apa pun —
            // "6 alpha" baru berarti setelah terlihat kemarin cuma 1.
            'tren' => $this->tren($tanggal),

            'lemburHariIni' => \App\Models\OvertimeRecord::query()
                ->with('employee')
                ->whereDate('work_date', $tanggal)
                ->get(),

            'periodeBerjalan' => \App\Models\PayrollPeriod::query()
                ->whereDate('start_date', '<=', $tanggal)
                ->whereDate('end_date', '>=', $tanggal)
                ->first(),

            'belumDihitung' => $rekap->isEmpty() && Employee::tracked()->exists(),
        ]);
    }

    /**
     * Saring & urutkan khusus untuk tabel "Absensi Hari Ini".
     *
     * Sengaja dipisah dari $rekap yang dipakai kartu ringkasan & tren —
     * kartu "Kehadiran: 15" harus tetap menghitung SEMUA orang hari itu
     * biarpun tabelnya lagi disaring cuma Shift Malam, kalau tidak angkanya
     * jadi menyesatkan seolah cuma segitu total karyawan yang masuk.
     *
     * @param  Collection<int, Attendance>  $rekap
     * @return Collection<int, Attendance>
     */
    protected function saringDanUrutkan(Collection $rekap, string $shiftFilter, string $kondisi, string $urutan): Collection
    {
        $hasil = $rekap
            ->when($shiftFilter !== '', fn ($q) => $q->where('shift_id', (int) $shiftFilter))
            ->when($kondisi !== '', fn ($q) => $q->filter(fn (Attendance $a) => match ($kondisi) {
                'telat' => $a->late_minutes > 0,
                'pulang_cepat' => $a->early_leave_minutes > 0,
                'lembur' => $a->overtime_minutes > 0,
                default => $a->status->value === $kondisi,
            }));

        $terurut = match ($urutan) {
            // Yang paling telat duluan — itulah gunanya urut dari telat.
            'telat' => $hasil->sortByDesc(fn (Attendance $a) => $a->late_minutes),
            'shift' => $hasil->sortBy([
                fn ($a, $b) => strcmp((string) $a->shift?->name, (string) $b->shift?->name),
                fn ($a, $b) => strcmp((string) $a->employee?->name, (string) $b->employee?->name),
            ]),
            'jam_masuk' => $hasil->sortBy(fn (Attendance $a) => $a->check_in_at?->timestamp ?? PHP_INT_MAX),
            'status' => $hasil->sortBy([
                fn ($a, $b) => strcmp((string) $a->status->value, (string) $b->status->value),
                fn ($a, $b) => strcmp((string) $a->employee?->name, (string) $b->employee?->name),
            ]),
            default => $hasil->sortBy(fn (Attendance $a) => $a->employee?->name),
        };

        return $terurut->values();
    }

    /**
     * Rekap tujuh hari terakhir untuk grafik batang.
     *
     * Dibaca dari tabel attendances, bukan dihitung ulang — angkanya harus
     * sama persis dengan yang tampil di kartu dan di laporan.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function tren(Carbon $sampai): array
    {
        $mulai = $sampai->copy()->subDays(6);

        $rekap = Attendance::query()
            ->whereBetween('work_date', [$mulai, $sampai])
            ->get()
            ->groupBy(fn (Attendance $a) => $a->work_date->toDateString());

        $hasil = [];

        for ($d = $mulai->copy(); $d->lessThanOrEqualTo($sampai); $d->addDay()) {
            $baris = $rekap->get($d->toDateString(), collect());

            $hasil[] = [
                'tanggal' => $d->copy(),
                'hadir' => $baris->where('status', AttendanceStatus::Hadir)->count(),
                'telat' => $baris->filter(fn ($a) => $a->late_minutes > 0)->count(),
                'alpha' => $baris->where('status', AttendanceStatus::Alpha)->count(),
            ];
        }

        return $hasil;
    }

    protected function tanggalDiminta(Request $request, string $timezone): Carbon
    {
        // Tanggal ngawur di URL cukup dikembalikan ke hari ini, bukan bikin
        // halaman error. DateInput menolak juga tanggal yang "hampir sah"
        // seperti 2026-02-31, yang kalau lolos akan bergeser diam-diam.
        //
        // Default-nya (tanpa parameter) BUKAN Carbon::today() — lihat
        // OperationalDate untuk alasannya: sebelum jam cutover, "hari ini"
        // yang relevan masih tanggal kemarin karena shift malam belum kelar.
        return DateInput::parse($request->query('tanggal'), $timezone)
            ?? OperationalDate::today($timezone);
    }
}
