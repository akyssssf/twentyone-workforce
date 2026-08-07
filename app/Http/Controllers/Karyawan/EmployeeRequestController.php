<?php

namespace App\Http\Controllers\Karyawan;

use App\Enums\RequestType;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Request as PengajuanModel;
use App\Models\RosterAssignment;
use App\Services\Requests\OvertimeCodeService;
use App\Services\Requests\RequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Satu modul untuk keempat jenis pengajuan (BR-17).
 *
 * Bentuk formulirnya berbeda per jenis, tapi alur kirim–lacak–batalkan sama
 * persis karena semuanya bermuara ke tabel `requests` yang sama.
 */
class EmployeeRequestController extends Controller
{
    public function __construct(
        protected RequestService $service,
        protected OvertimeCodeService $overtimeCode,
    ) {}

    public function index(Request $request)
    {
        $employeeId = $request->user()->employee_id;

        return view('karyawan.pengajuan.index', [
            'requests' => PengajuanModel::query()
                ->with(['leave.leaveType', 'overtime', 'swap.partner', 'correction'])
                ->where('employee_id', $employeeId)
                ->latest('id')
                ->paginate(20),

            // Pengajuan yang menunjuk SAYA sebagai pengganti, apa pun jenisnya.
            'menungguJawaban' => PengajuanModel::query()
                ->with(['employee', 'leave.leaveType', 'overtime', 'swap.requesterAssignment.shift', 'correction'])
                ->where('status', 'pending_peer')
                ->where('substitute_employee_id', $employeeId)
                ->get(),
        ]);
    }

    public function create(string $type, Request $request)
    {
        $requestType = RequestType::tryFrom($type);
        abort_if($requestType === null, 404);

        // Lembur tidak bisa diajukan sendiri — selalu berawal dari penunjukan
        // admin, dan karyawan menerima kodenya.
        abort_unless($requestType->isSelfService(), 404);

        $employee = $request->user()->employee;

        return view('karyawan.pengajuan.create', [
            'type' => $requestType,
            'employee' => $employee,
            'leaveTypes' => LeaveType::query()->active()->get(),
            'saldoCuti' => $employee->leaveBalances()->with('leaveType')->where('year', now()->year)->get(),

            // Jadwal saya yang masih di masa depan — kandidat untuk ditukar.
            'jadwalSaya' => RosterAssignment::query()
                ->with(['shift', 'division'])
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', '>=', today())
                ->working()
                ->orderBy('work_date')
                ->limit(60)
                ->get(),

            // Kandidat pengganti. Hanya yang ikut diabsen: admin tidak bisa
            // menutup shift karena memang tidak dijadwalkan.
            'rekan' => Employee::query()
                ->tracked()
                ->where('id', '!=', $employee->id)
                ->with('divisions')
                ->orderBy('name')
                ->get(),

            // Absensi 30 hari terakhir — kandidat untuk dikoreksi.
            'absensiTerakhir' => Attendance::query()
                ->with('shift')
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', '>=', today()->subDays(30))
                ->orderByDesc('work_date')
                ->get(),
        ]);
    }

    public function store(string $type, Request $request)
    {
        $requestType = RequestType::tryFrom($type);
        abort_if($requestType === null, 404);
        abort_unless($requestType->isSelfService(), 404);

        $employee = $request->user()->employee;

        try {
            $pengajuan = match ($requestType) {
                RequestType::Leave => $this->service->submitLeave($employee, $request->validate([
                    'leave_type_id' => ['required', 'exists:leave_types,id'],
                    'start_date' => ['required', 'date'],
                    'end_date' => ['required', 'date', 'after_or_equal:start_date'],
                    'reason' => ['required', 'string', 'min:5'],
                    'handover_note' => ['nullable', 'string'],
                    'substitute_employee_id' => ['required', 'exists:employees,id'],
                ])),

                // Lembur sengaja tidak ada di sini: satu-satunya jalannya
                // adalah penunjukan admin + kode aktivasi.
                RequestType::Overtime => abort(404),

                RequestType::Swap => $this->service->submitSwap($employee, $request->validate([
                    'requester_assignment_id' => ['required', 'exists:roster_assignments,id'],
                    'partner_employee_id' => ['required', 'exists:employees,id'],
                    'partner_assignment_id' => ['nullable', 'exists:roster_assignments,id'],
                    'reason' => ['required', 'string', 'min:5'],
                ])),

                RequestType::Correction => $this->service->submitCorrection($employee, $request->validate([
                    'work_date' => ['required', 'date'],
                    'shift_key' => ['nullable', 'integer'],
                    'correction_type' => ['required', 'in:lupa_masuk,lupa_pulang,mesin_error,lainnya'],
                    'proposed_check_in' => ['nullable', 'date'],
                    'proposed_check_out' => ['nullable', 'date'],
                    'reason' => ['required', 'string', 'min:5'],
                    'substitute_employee_id' => ['required', 'exists:employees,id'],
                ])),
            };
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['pengajuan' => $e->getMessage()]);
        }

        return redirect()
            ->route('karyawan.pengajuan.show', $pengajuan)
            ->with('status', "Pengajuan {$pengajuan->code} terkirim.");
    }

    public function show(PengajuanModel $request, Request $httpRequest)
    {
        $employeeId = $httpRequest->user()->employee_id;

        // Boleh dilihat kalau ini pengajuan saya, ATAU saya rekan yang diminta
        // pada pengajuan tukar shift ini.
        $milikSaya = $request->employee_id === $employeeId;

        // Pengganti yang ditunjuk berhak melihat — dia yang harus memutuskan
        // bersedia atau tidak, dan tidak bisa memutuskan tanpa melihat isinya.
        $sayaRekan = $request->substitute_employee_id === $employeeId;

        abort_unless($milikSaya || $sayaRekan, 403);

        return view('karyawan.pengajuan.show', [
            'pengajuan' => $request->load([
                'employee', 'decider', 'attachments',
                'leave.leaveType', 'overtime', 'swap.partner', 'substitute',
                'swap.requesterAssignment.shift', 'swap.partnerAssignment.shift',
                'correction',
            ]),
            'milikSaya' => $milikSaya,
            'sayaRekan' => $sayaRekan,
        ]);
    }

    /**
     * Aktivasi lembur dengan kode rahasia.
     *
     * Hanya orang yang ditunjuk yang memegang kodenya, jadi berhasilnya
     * aktivasi sekaligus jadi bukti bahwa dia memang yang mengerjakan.
     */
    public function activateOvertime(Request $httpRequest)
    {
        $data = $httpRequest->validate([
            'kode' => ['required', 'string', 'max:12'],
        ]);

        try {
            $record = $this->overtimeCode->activate($httpRequest->user()->employee, $data['kode']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['lembur' => $e->getMessage()]);
        }

        return back()->with('status',
            'Lembur ' . $record->work_date->translatedFormat('d M Y') . ' aktif. Selamat bekerja.');
    }

    public function cancel(PengajuanModel $request, Request $httpRequest)
    {
        abort_unless($request->employee_id === $httpRequest->user()->employee_id, 403);

        try {
            $this->service->cancel($request);
        } catch (RuntimeException $e) {
            return back()->withErrors(['pengajuan' => $e->getMessage()]);
        }

        return back()->with('status', 'Pengajuan dibatalkan.');
    }

    /** Pengganti menerima atau menolak — tahap sebelum manajer, untuk semua jenis. */
    public function respond(PengajuanModel $request, Request $httpRequest)
    {
        $data = $httpRequest->validate([
            'accepted' => ['required', 'boolean'],
            'note' => ['nullable', 'string'],
        ]);

        try {
            $this->service->peerRespond(
                $request,
                $httpRequest->user()->employee,
                (bool) $data['accepted'],
                $data['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['pengajuan' => $e->getMessage()]);
        }

        return back()->with('status', $data['accepted']
            ? 'Anda bersedia menggantikan. Sekarang menunggu persetujuan manajer.'
            : 'Anda menyatakan tidak bisa menggantikan.');
    }
}
