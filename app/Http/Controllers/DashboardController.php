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
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        $rekap = Attendance::with(['employee', 'shift', 'division'])
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

        return view('dashboard.index', [
            'tanggal' => $tanggal,
            'rekap' => $rekap,
            'scan' => $scan,
            'jadwal' => $jadwal,
            'shifts' => Shift::query()->where('is_active', true)->get(),

            // PIN yang mengirim scan tapi tidak dikenali pemetaan mana pun.
            // Scan-nya tersimpan aman, tapi belum masuk rekap siapa pun.
            'pinAsing' => $scan->whereNull('employee_id')->pluck('pin')->unique()->values(),

            'ringkasan' => [
                'karyawan' => Employee::active()->count(),
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

            'belumDihitung' => $rekap->isEmpty() && Employee::active()->exists(),
        ]);
    }

    protected function tanggalDiminta(Request $request, string $timezone): Carbon
    {
        // Tanggal ngawur di URL cukup dikembalikan ke hari ini, bukan bikin
        // halaman error. DateInput menolak juga tanggal yang "hampir sah"
        // seperti 2026-02-31, yang kalau lolos akan bergeser diam-diam.
        return DateInput::parse($request->query('tanggal'), $timezone)
            ?? Carbon::today($timezone);
    }
}
