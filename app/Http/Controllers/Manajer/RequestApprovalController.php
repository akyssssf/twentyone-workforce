<?php

namespace App\Http\Controllers\Manajer;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\Request as PengajuanModel;
use App\Models\Shift;
use App\Services\Audit\AuditLogger;
use App\Services\Requests\RequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

class RequestApprovalController extends Controller
{
    public function __construct(
        protected RequestService $service,
    ) {}

    public function index(Request $request)
    {
        $query = PengajuanModel::query()
            ->with(['employee', 'substitute', 'leave.leaveType', 'overtime', 'swap.partner', 'correction'])
            ->latest('id');

        $status = $request->query('status', 'pending');

        if ($status === 'pending') {
            $query->pending();
        } elseif ($status !== 'semua') {
            $query->where('status', $status);
        }

        if ($type = $request->query('jenis')) {
            $query->where('type', $type);
        }

        return view('manajer.pengajuan.index', [
            'requests' => $query->paginate(30)->withQueryString(),
            'status' => $status,
            'jumlahPending' => PengajuanModel::query()->awaitingManager()->count(),
        ]);
    }

    public function show(PengajuanModel $request)
    {
        return view('manajer.pengajuan.show', [
            'pengajuan' => $request->load([
                'employee', 'substitute', 'decider', 'attachments',
                'leave.leaveType', 'overtime', 'swap.partner',
                'swap.requesterAssignment.shift', 'swap.partnerAssignment.shift',
                'correction',
            ]),
        ]);
    }

    public function approve(PengajuanModel $request, Request $httpRequest)
    {
        try {
            $this->service->approve($request, $httpRequest->user(), $httpRequest->input('note'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['pengajuan' => $e->getMessage()]);
        }

        return back()->with('status', "Pengajuan {$request->code} disetujui.");
    }

    /**
     * Admin menandai pengganti sudah setuju setelah dihubungi langsung
     * (telepon/WA pribadi), tanpa penggantinya login dan klik apa pun.
     */
    public function confirmSubstitute(PengajuanModel $request, Request $httpRequest)
    {
        try {
            $this->service->confirmSubstituteByAdmin($request, $httpRequest->user(), $httpRequest->input('note'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['pengajuan' => $e->getMessage()]);
        }

        return back()->with('status', "Pengganti untuk {$request->code} ditandai sudah setuju.");
    }

    public function reject(PengajuanModel $request, Request $httpRequest)
    {
        // Alasan penolakan wajib. Penolakan tanpa penjelasan adalah cara
        // tercepat menghancurkan kepercayaan pada sistem.
        $data = $httpRequest->validate([
            'note' => ['required', 'string', 'min:5'],
        ]);

        try {
            $this->service->reject($request, $httpRequest->user(), $data['note']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['pengajuan' => $e->getMessage()]);
        }

        return back()->with('status', "Pengajuan {$request->code} ditolak.");
    }

    // ---------------------------------------------------------------- lembur

    public function overtime()
    {
        return view('manajer.lembur.index', [
            'employees' => Employee::query()->active()->orderBy('name')->get(),
            'shifts' => Shift::query()->where('is_active', true)->get(),

            // Realisasi yang menunggu disahkan. Inilah titik kedua approval:
            // approval pertama merestui rencananya, yang ini mengesahkan
            // jam yang benar-benar dikerjakan.
            'records' => OvertimeRecord::query()
                ->with(['employee', 'overtimeRequest'])
                ->where('status', 'pending_confirmation')
                ->orderByDesc('work_date')
                ->get(),

            // Penugasan yang sudah disetujui tapi kodenya belum dipakai —
            // daftar orang yang perlu diingatkan sebelum shift dimulai.
            'belumAktif' => OvertimeRecord::query()
                ->with(['employee', 'overtimeRequest'])
                ->whereNull('activated_at')
                ->whereDate('work_date', '>=', today()->subDay())
                ->orderBy('work_date')
                ->get(),

            'confirmed' => OvertimeRecord::query()
                ->with(['employee', 'overtimeRequest'])
                ->confirmed()
                ->orderByDesc('work_date')
                ->limit(30)
                ->get(),
        ]);
    }

    /** Manager membuat lembur untuk beberapa karyawan sekaligus. */
    public function storeOvertime(Request $request)
    {
        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['exists:employees,id'],
            'work_date' => ['required', 'date'],
            'planned_start' => ['required'],
            'planned_end' => ['required'],
            'shift_id' => ['nullable', 'exists:shifts,id'],
            'substitute_employee_id' => ['required', 'exists:employees,id'],
            'reason' => ['required', 'string', 'min:5'],
        ]);

        // Satu request per karyawan — karena tiap orang punya realisasi dan
        // approval sendiri — tapi dikelompokkan satu batch supaya UI bisa
        // menampilkannya sebagai satu tindakan.
        $batchId = (string) Str::uuid();
        $dibuat = 0;

        foreach ($data['employee_ids'] as $employeeId) {
            $employee = Employee::findOrFail($employeeId);

            try {
                $pengajuan = $this->service->submitOvertime($employee, [
                    'batch_id' => $batchId,
                    'work_date' => $data['work_date'],
                    'planned_start' => $data['planned_start'],
                    'planned_end' => $data['planned_end'],
                    'shift_id' => $data['shift_id'] ?? null,
                    'substitute_employee_id' => $data['substitute_employee_id'],
                    'reason' => $data['reason'],
                ], 'manager');

                // Dibuat manager berarti sekaligus disetujui manager.
                $this->service->approve($pengajuan, $request->user(), 'Dibuat dan disetujui manager');
                $dibuat++;
            } catch (RuntimeException $e) {
                return back()->withErrors(['lembur' => $e->getMessage()]);
            }
        }

        return back()->with('status',
            "{$dibuat} penugasan lembur dibuat dan disetujui. "
            . 'Bagikan kode masing-masing ke orangnya — tanpa kode, lemburnya tidak bisa diaktifkan.');
    }

    /**
     * Sahkan realisasi lembur.
     *
     * Yang dibayar min(disetujui, aktual). Manager boleh menaikkannya, tapi
     * harus mengisi catatan — kenaikan tanpa penjelasan adalah lubang paling
     * mudah disalahgunakan di seluruh sistem penggajian.
     */
    public function confirmOvertime(OvertimeRecord $record, Request $request)
    {
        $data = $request->validate([
            'actual_minutes' => ['required', 'integer', 'min:0'],
            'payable_minutes' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string'],
        ]);

        if ($data['payable_minutes'] > $record->approved_minutes && blank($data['note'])) {
            return back()->withErrors([
                'lembur' => 'Menit dibayar melebihi yang disetujui. Isi catatan alasannya.',
            ]);
        }

        // Lembur yang tidak pernah diaktifkan pakai kode berarti tidak ada
        // bukti orang yang ditunjuk benar-benar yang mengerjakannya.
        if (! $record->isActivated() && $data['payable_minutes'] > 0 && blank($data['note'])) {
            return back()->withErrors([
                'lembur' => "{$record->employee?->name} belum mengaktifkan lembur ini dengan kodenya. "
                    . 'Isi catatan kalau tetap mau disahkan.',
            ]);
        }

        $record->update([
            'actual_minutes' => $data['actual_minutes'],
            'payable_minutes' => $data['payable_minutes'],
            'status' => 'confirmed',
            'confirmed_by' => $request->user()->id,
            'confirmed_at' => now(),
            'note' => $data['note'],
        ]);

        AuditLogger::record('overtime.confirmed', $record, [], $data);

        return back()->with('status', 'Realisasi lembur disahkan.');
    }
}
