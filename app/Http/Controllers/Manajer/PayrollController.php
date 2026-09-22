<?php

namespace App\Http\Controllers\Manajer;

use App\Http\Controllers\Controller;
use App\Models\CashAdvance;
use App\Models\CashAdvanceInstallment;
use App\Models\Employee;
use App\Models\ManualPayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Services\Audit\AuditLogger;
use App\Services\Payroll\KasbonService;
use App\Services\Payroll\PayrollGenerator;
use App\Services\Payroll\PayrollPeriodFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

class PayrollController extends Controller
{
    public function __construct(
        protected PayrollGenerator $generator,
        protected PayrollPeriodFactory $factory,
        protected KasbonService $kasbon,
    ) {}

    public function index()
    {
        // Periode berjalan selalu ada di daftar, supaya estimasi hariannya
        // bisa dibuka tanpa harus "membuat periode" dulu.
        $this->factory->covering(now());

        return view('manajer.payroll.index', [
            'periods' => PayrollPeriod::query()->with('runs')->orderByDesc('start_date')->get(),
            'bulanIni' => now(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $period = $this->factory->forMonth((int) $data['year'], (int) $data['month']);

        return redirect()->route('manajer.payroll.show', $period);
    }

    public function show(PayrollPeriod $period)
    {
        $run = $period->activeRun();

        return view('manajer.payroll.show', [
            'period' => $period,
            'run' => $run,
            'payslips' => $run?->payslips()->with('employee')->get() ?? collect(),
            'employees' => Employee::query()->active()->orderBy('name')->get(),
            'entries' => $period->manualEntries()->with(['employee', 'deductionType'])->get(),
            'deductionTypes' => \App\Models\DeductionType::manual()->get(),

            // Cicilan kasbon yang jatuh tempo di periode ini, apa pun statusnya,
            // supaya manajer melihat mana yang sudah masuk slip dan mana yang
            // masih menunggu hitung ulang.
            'cicilanKasbon' => CashAdvanceInstallment::query()
                ->with(['cashAdvance.employee'])
                ->where('payroll_period_id', $period->id)
                ->whereHas('cashAdvance', fn ($q) => $q->where('status', 'disbursed'))
                ->get()
                ->sortBy(fn ($c) => $c->cashAdvance?->employee?->name)
                ->values(),
        ]);
    }

    public function generate(PayrollPeriod $period)
    {
        try {
            $run = $this->generator->generate($period);
        } catch (RuntimeException $e) {
            return back()->withErrors(['payroll' => $e->getMessage()]);
        }

        return back()->with('status', "Payroll dihitung (versi {$run->version}) untuk {$run->employee_count} karyawan.");
    }

    public function approve(PayrollPeriod $period)
    {
        try {
            $this->factory->approve($period);
        } catch (RuntimeException $e) {
            return back()->withErrors(['payroll' => $e->getMessage()]);
        }

        return back()->with('status', 'Payroll disetujui. Slip gaji sekarang bisa dilihat karyawan.');
    }

    public function lock(PayrollPeriod $period)
    {
        try {
            $this->factory->lock($period);
        } catch (RuntimeException $e) {
            return back()->withErrors(['payroll' => $e->getMessage()]);
        }

        return back()->with('status', 'Periode dikunci. Absensi dan roster di rentang ini tidak bisa diubah lagi.');
    }

    public function reopen(PayrollPeriod $period, Request $request)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10'],
        ]);

        try {
            $this->factory->reopen($period, $data['reason']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['payroll' => $e->getMessage()]);
        }

        return back()->with('status', 'Periode dibuka ulang. Ingat: slip yang sudah diterima karyawan akan berubah.');
    }

    /** Bonus dan potongan manual. Keduanya wajib beralasan (BR-23). */
    public function storeEntry(PayrollPeriod $period, Request $request)
    {
        if ($period->isLocked()) {
            return back()->withErrors(['payroll' => 'Periode ini sudah dikunci.']);
        }

        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'entry_type' => ['required', 'in:bonus,deduction'],
            'deduction_type_id' => ['nullable', 'exists:deduction_types,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5'],
        ]);

        $entry = ManualPayrollEntry::create($data + [
            'payroll_period_id' => $period->id,
            'created_by' => $request->user()->id,
        ]);

        AuditLogger::record('payroll.manual_entry', $entry, [], $data);

        return back()->with('status', 'Entri disimpan. Jalankan hitung ulang supaya masuk ke slip.');
    }

    /**
     * Kasbon dicatat dari halaman periode: uangnya sudah diterima karyawan,
     * cicilan pertama dipotong di periode ini, sisanya di periode berikutnya.
     */
    public function storeKasbon(PayrollPeriod $period, Request $request)
    {
        if ($period->isLocked()) {
            return back()->withErrors(['kasbon' => 'Periode ini sudah dikunci.']);
        }

        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'installments' => ['required', 'integer', 'min:1', 'max:12'],
            'disbursed_at' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $this->kasbon->catat(
                Employee::findOrFail($data['employee_id']),
                (int) $data['amount'],
                $period->code,
                (int) $data['installments'],
                $data['reason'] ?? null,
                ! empty($data['disbursed_at']) ? Carbon::parse($data['disbursed_at']) : null,
                $request->user()->id,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['kasbon' => $e->getMessage()]);
        }

        return back()->with('status', 'Kasbon dicatat. Jalankan hitung ulang supaya potongannya masuk ke slip.');
    }

    public function cancelKasbon(PayrollPeriod $period, CashAdvance $kasbon)
    {
        if ($kasbon->status !== 'disbursed') {
            return back()->withErrors(['kasbon' => 'Kasbon ini sudah tidak berjalan.']);
        }

        $this->kasbon->batalkan($kasbon, 'Dibatalkan dari halaman payroll '.$period->code);

        return back()->with('status', 'Cicilan kasbon yang belum terpotong dibatalkan. Hitung ulang kalau slip periode ini sudah ada.');
    }

    public function payslip(Payslip $payslip)
    {
        return view('slip.show', [
            'payslip' => $payslip->load(['items', 'employee', 'run.period']),
            'kembali' => route('manajer.payroll.show', $payslip->run->payroll_period_id),
        ]);
    }
}
