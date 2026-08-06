<?php

namespace App\Http\Controllers\Karyawan;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Notification;
use App\Models\Payslip;
use App\Models\Request as PengajuanModel;
use App\Models\Roster;
use App\Models\RosterAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Portal karyawan.
 *
 * Setiap query di sini DIKUNCI ke employee_id milik yang login. Bukan sekadar
 * menyembunyikan tautan di menu — data orang lain memang tidak pernah ikut
 * terambil.
 */
class EmployeePortalController extends Controller
{
    public function index(Request $request)
    {
        $employee = $request->user()->employee;
        $hariIni = Carbon::today(config('attendance.timezone'));

        return view('karyawan.beranda', [
            'employee' => $employee,

            'jadwalHariIni' => RosterAssignment::query()
                ->with(['shift', 'division'])
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $hariIni)
                ->whereHas('roster', fn ($q) => $q->visibleToEmployee())
                ->get(),

            'absensiHariIni' => Attendance::query()
                ->with('shift')
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $hariIni)
                ->get(),

            'jadwalMendatang' => RosterAssignment::query()
                ->with(['shift', 'division'])
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', '>', $hariIni)
                ->whereHas('roster', fn ($q) => $q->visibleToEmployee())
                ->orderBy('work_date')
                ->limit(7)
                ->get(),

            'pengajuanTerbuka' => PengajuanModel::query()
                ->where('employee_id', $employee->id)
                ->pending()
                ->latest('id')
                ->get(),

            // Tukar shift yang menunggu jawaban SAYA sebagai rekan.
            'menungguJawaban' => PengajuanModel::query()
                ->with(['employee', 'swap.requesterAssignment.shift'])
                ->where('status', 'pending_peer')
                ->whereHas('swap', fn ($q) => $q->where('partner_employee_id', $employee->id))
                ->get(),

            'saldoCuti' => $employee->leaveBalances()->with('leaveType')->where('year', now()->year)->get(),

            'slipTerbaru' => Payslip::query()
                ->with('run.period')
                ->where('employee_id', $employee->id)
                ->published()
                ->latest('id')
                ->first(),

            'notifikasi' => Notification::query()
                ->where('user_id', $request->user()->id)
                ->latest('id')
                ->limit(5)
                ->get(),
        ]);
    }

    public function roster(Request $request)
    {
        $employee = $request->user()->employee;

        $bulan = $request->query('bulan')
            ? Carbon::parse($request->query('bulan') . '-01')
            : now();

        $roster = Roster::query()
            ->visibleToEmployee()
            ->where('period_year', $bulan->year)
            ->where('period_month', $bulan->month)
            ->first();

        $assignments = $roster
            ? RosterAssignment::query()
                ->with(['shift', 'division'])
                ->where('employee_id', $employee->id)
                ->where('roster_id', $roster->id)
                ->orderBy('work_date')
                ->get()
                ->keyBy(fn ($a) => $a->work_date->toDateString())
            : collect();

        return view('karyawan.jadwal', [
            'employee' => $employee,
            'roster' => $roster,
            'bulan' => $bulan,
            'assignments' => $assignments,
        ]);
    }

    public function attendance(Request $request)
    {
        $employee = $request->user()->employee;

        $bulan = $request->query('bulan')
            ? Carbon::parse($request->query('bulan') . '-01')
            : now();

        $attendances = Attendance::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$bulan->copy()->startOfMonth(), $bulan->copy()->endOfMonth()])
            ->orderBy('work_date')
            ->get();

        return view('karyawan.absensi', [
            'employee' => $employee,
            'bulan' => $bulan,
            'attendances' => $attendances,
            'ringkasan' => [
                'hadir' => $attendances->where('status', \App\Enums\AttendanceStatus::Hadir)->count(),
                'telat' => $attendances->filter(fn ($a) => $a->late_minutes > 0)->count(),
                'pulang_cepat' => $attendances->filter(fn ($a) => $a->early_leave_minutes > 0)->count(),
                'alpha' => $attendances->where('status', \App\Enums\AttendanceStatus::Alpha)->count(),
                'total_telat_menit' => $attendances->sum('late_minutes'),
                'lembur_menit' => $attendances->sum('overtime_minutes'),
            ],
        ]);
    }
}
