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

            // Siapa saja yang bertugas hari ini, dikelompokkan per shift.
            // Karyawan perlu tahu ini untuk hal sesederhana "hari ini aku
            // sedapur sama siapa" — dan untuk memilih pengganti saat mau
            // mengajukan cuti.
            'rosterHariIni' => RosterAssignment::query()
                ->with(['employee', 'shift', 'division'])
                ->whereDate('work_date', $hariIni)
                ->working()
                ->whereHas('roster', fn ($q) => $q->visibleToEmployee())
                ->get()
                ->groupBy('shift_id'),

            'shifts' => \App\Models\Shift::query()->where('is_active', true)->orderBy('start_time')->get(),

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

            // Pengajuan yang menunjuk SAYA sebagai pengganti. Ditaruh paling
            // atas di beranda karena inilah satu-satunya hal di aplikasi ini
            // yang menahan orang lain selama belum dijawab.
            'menungguJawaban' => PengajuanModel::query()
                ->with(['employee', 'leave.leaveType', 'overtime', 'swap.requesterAssignment.shift', 'correction'])
                ->where('status', 'pending_peer')
                ->where('substitute_employee_id', $employee->id)
                ->get(),

            'saldoCuti' => $employee->leaveBalances()->with('leaveType')->where('year', now()->year)->get(),

            'slipTerbaru' => Payslip::query()
                ->with('run.period')
                ->where('employee_id', $employee->id)
                ->published()
                ->latest('id')
                ->first(),

            // Lembur yang sudah disetujui tapi kodenya belum dipakai.
            'lemburBelumAktif' => app(\App\Services\Requests\OvertimeCodeService::class)->pendingFor($employee),

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

        // Tanggal yang sedang dibuka rinciannya. Dipakai query string, bukan
        // JavaScript, supaya tautannya bisa dibagikan dan tetap jalan kalau
        // koneksi di kafe sedang buruk.
        $pilih = $request->query('tanggal')
            ? Carbon::parse($request->query('tanggal'))->startOfDay()
            : null;

        $rekanHariItu = $pilih
            ? RosterAssignment::query()
                ->with(['employee', 'shift', 'division'])
                ->whereDate('work_date', $pilih)
                ->working()
                ->when($roster, fn ($q) => $q->where('roster_id', $roster->id))
                ->get()
                ->groupBy('shift_id')
            : collect();

        return view('karyawan.jadwal', [
            'employee' => $employee,
            'roster' => $roster,
            'bulan' => $bulan,
            'assignments' => $assignments,
            'pilih' => $pilih,
            'rekanHariItu' => $rekanHariItu,

            // Dipakai keterangan warna di bawah kalender. Wajib ada karena di
            // ponsel selnya cuma memuat satu huruf kode shift.
            'shifts' => \App\Models\Shift::query()->where('is_active', true)->orderBy('start_time')->get(),
        ]);
    }

    /**
     * Halaman lembur karyawan.
     *
     * Dibuat sebagai halaman tersendiri, bukan sekadar kartu di beranda, karena
     * inilah satu-satunya cara lembur jadi terhitung. Kalau tersembunyi di
     * antara kartu lain, orang yang ditunjuk malam itu harus mencari-cari
     * tempat memasukkan kodenya — dan yang tidak ketemu akhirnya bekerja tanpa
     * dibayar.
     */
    public function overtime(Request $request)
    {
        $employee = $request->user()->employee;

        return view('karyawan.lembur', [
            'employee' => $employee,

            'belumAktif' => app(\App\Services\Requests\OvertimeCodeService::class)->pendingFor($employee),

            'riwayat' => \App\Models\OvertimeRecord::query()
                ->with('overtimeRequest')
                ->where('employee_id', $employee->id)
                ->orderByDesc('work_date')
                ->limit(30)
                ->get(),
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
