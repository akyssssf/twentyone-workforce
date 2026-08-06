<?php

namespace App\Services\Payroll;

use App\Enums\AttendanceStatus;
use App\Enums\PayrollStatus;
use App\Enums\RuleType;
use App\Models\Attendance;
use App\Models\CashAdvanceInstallment;
use App\Models\Employee;
use App\Models\ManualPayrollEntry;
use App\Models\OvertimeRecord;
use App\Models\PayrollAdjustment;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\RosterAssignment;
use App\Models\SalaryComponent;
use App\Services\Audit\AuditLogger;
use App\Services\Rules\RuleResolver;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Perhitungan payroll satu periode.
 *
 * Membaca HANYA dari Final Attendance (tabel attendances) dan
 * overtime_records — tidak pernah menyentuh attendance_logs. Larangan BR-02
 * ditegakkan secara struktural: tidak ada satu pun query ke tabel scan mentah
 * di seluruh modul ini.
 *
 * Uang dihitung di sini dan HANYA di sini. Modul absensi mencatat menit; kelas
 * ini yang menerjemahkannya jadi rupiah, sekali, lalu membekukannya bersama
 * salinan aturan yang dipakai. Mengubah tarif besok tidak akan mengubah slip
 * yang sudah terbit.
 */
class PayrollGenerator
{
    public function __construct(
        protected RuleResolver $rules,
    ) {}

    public function generate(PayrollPeriod $period): PayrollRun
    {
        if (! $period->status->canGenerate()) {
            throw new RuntimeException(
                "Periode {$period->code} berstatus {$period->status->label()} dan tidak bisa dihitung ulang. "
                . 'Buka kunci periode ini lebih dulu kalau memang harus diubah.'
            );
        }

        // Versi baru, bukan menimpa. Percobaan sebelumnya tetap ada sebagai
        // bukti kalau nanti ada yang bertanya.
        $version = (int) $period->runs()->max('version') + 1;

        $period->runs()->where('status', 'completed')->update(['status' => 'superseded']);

        $run = PayrollRun::create([
            'payroll_period_id' => $period->id,
            'version' => $version,
            'status' => 'running',
            'generated_by' => auth()->id(),
            'started_at' => now(),
            'rule_snapshot' => $this->ruleSnapshot($period),
        ]);

        $period->update(['status' => PayrollStatus::Generating]);

        try {
            $employees = Employee::query()
                ->active()
                ->with(['divisions', 'salaries.component'])
                ->orderBy('name')
                ->get();

            $total = 0;

            foreach ($employees as $employee) {
                // Sengaja satu transaksi per karyawan, bukan satu transaksi
                // raksasa: SQLite hanya mengizinkan satu penulis, dan transaksi
                // panjang akan memblokir webhook mesin yang datang bersamaan.
                $payslip = DB::transaction(fn () => $this->generatePayslip($run, $employee, $period));
                $total += $payslip->take_home_pay;
            }

            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
                'employee_count' => $employees->count(),
                'total_take_home_pay' => $total,
            ]);

            $period->update(['status' => PayrollStatus::Generated]);

            AuditLogger::record('payroll.generated', $period, [], [
                'version' => $version,
                'employees' => $employees->count(),
                'total' => $total,
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            $period->update(['status' => PayrollStatus::Open]);

            throw $e;
        }

        return $run->fresh();
    }

    protected function generatePayslip(PayrollRun $run, Employee $employee, PayrollPeriod $period): Payslip
    {
        $attendances = Attendance::query()
            ->where('employee_id', $employee->id)
            ->inPeriod($period->start_date, $period->end_date)
            ->get();

        $scheduledDays = $attendances->filter(fn ($a) => ! $a->status->isNonWorking())->count();
        $presentDays = $attendances->where('status', AttendanceStatus::Hadir)->count();
        $absentDays = $attendances->where('status', AttendanceStatus::Alpha)->count();
        $leaveDays = $attendances->filter(fn ($a) => $a->status->isNonWorking() && $a->status !== AttendanceStatus::Libur)->count();

        $baseSalary = $employee->baseSalaryOn($period->end_date);
        $workingDays = $this->workingDays($employee, $period, $scheduledDays);

        $payslip = Payslip::updateOrCreate(
            ['payroll_run_id' => $run->id, 'employee_id' => $employee->id],
            [
                'code' => sprintf('SLIP-%s-%03d', $period->code, $employee->id),
                'employee_snapshot' => [
                    'name' => $employee->name,
                    'employee_no' => $employee->employee_no,
                    'division' => $employee->primaryDivision()?->name,
                    'pin' => $employee->pinOn($period->end_date),
                    'joined_at' => $employee->joined_at?->toDateString(),
                ],
                'scheduled_days' => $scheduledDays,
                'present_days' => $presentDays,
                'absent_days' => $absentDays,
                'leave_days' => $leaveDays,
                'late_count' => $attendances->filter(fn ($a) => $a->late_minutes > 0)->count(),
                'early_leave_count' => $attendances->filter(fn ($a) => $a->early_leave_minutes > 0)->count(),
                'status' => 'draft',
            ],
        );

        $payslip->items()->delete();

        $sort = 0;

        // --- Pendapatan ---
        $this->addItem($payslip, 'earning', 'Gaji Pokok', 1, $baseSalary, $baseSalary, $sort++, [
            'component' => 'gaji_pokok',
        ]);

        $overtimeMinutes = $this->addOvertime($payslip, $employee, $period, $baseSalary, $workingDays, $sort);
        $sort += 1;

        $this->addBonuses($payslip, $employee, $period, $sort);
        $sort += 1;

        $this->addAdjustments($payslip, $employee, $period, $sort);
        $sort += 1;

        // --- Potongan ---
        $this->addLateDeduction($payslip, $attendances, $period, $baseSalary, $workingDays, $sort++);
        $this->addEarlyLeaveDeduction($payslip, $attendances, $period, $baseSalary, $workingDays, $sort++);
        $this->addAbsentDeduction($payslip, $absentDays, $period, $baseSalary, $workingDays, $sort++);
        $this->addManualDeductions($payslip, $employee, $period, $sort++);
        $this->addCashAdvance($payslip, $employee, $period, $sort++);

        // --- Potongan wajib ---
        $this->addBpjs($payslip, $period, $baseSalary, $sort++);

        $items = $payslip->items()->get();

        $earning = (int) $items->where('category', 'earning')->sum('amount');
        $deduction = (int) $items->where('category', 'deduction')->sum('amount');
        $statutory = (int) $items->where('category', 'statutory')->sum('amount');

        $payslip->update([
            'total_earning' => $earning,
            'total_deduction' => $deduction,
            'total_statutory' => $statutory,
            'take_home_pay' => $earning - $deduction - $statutory,
            'overtime_minutes' => $overtimeMinutes,
        ]);

        return $payslip->fresh();
    }

    /**
     * Pembagi untuk tarif harian dan tarif per jam.
     *
     * Diambil dari ROSTER, bukan dari jumlah baris absensi yang sudah
     * terhitung. Bedanya besar: kalau absensi baru terisi sebagian — misalnya
     * payroll dijalankan di tengah periode, atau cron compute baru berjalan
     * untuk beberapa hari — memakai jumlah baris absensi membuat pembaginya
     * kecil, dan tarif harian melonjak sampai berkali-kali lipat. Potongan
     * alpha satu hari bisa memakan seluruh gaji sebulan.
     *
     * Urutan sumber: jadwal roster, lalu jumlah hari absensi, lalu 26 sebagai
     * jaring pengaman terakhir.
     */
    protected function workingDays(Employee $employee, PayrollPeriod $period, int $scheduledDays): int
    {
        $fromRoster = RosterAssignment::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$period->start_date, $period->end_date])
            ->working()
            ->count();

        if ($fromRoster > 0) {
            return $fromRoster;
        }

        return $scheduledDays > 0 ? $scheduledDays : 26;
    }

    protected function addOvertime(Payslip $payslip, Employee $employee, PayrollPeriod $period, int $baseSalary, int $workingDays, int $sort): int
    {
        $minutes = (int) OvertimeRecord::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$period->start_date, $period->end_date])
            ->confirmed()
            ->sum('payable_minutes');

        if ($minutes <= 0) {
            return 0;
        }

        $result = $this->rules->overtimePay($period->end_date, $minutes, $baseSalary, $workingDays);

        $this->addItem(
            $payslip,
            'earning',
            'Lembur ' . round($minutes / 60, 1) . ' jam',
            round($minutes / 60, 2),
            $result['amount'] > 0 && $minutes > 0 ? (int) round($result['amount'] / ($minutes / 60)) : 0,
            $result['amount'],
            $sort,
            ['breakdown' => $result['breakdown']],
            'overtime',
        );

        return $minutes;
    }

    protected function addLateDeduction(Payslip $payslip, $attendances, PayrollPeriod $period, int $baseSalary, int $workingDays, int $sort): void
    {
        $late = $attendances->filter(fn ($a) => $a->late_minutes > 0);

        if ($late->isEmpty()) {
            return;
        }

        $total = 0;
        $breakdown = [];

        // Dihitung per kejadian, bukan dari total menit sebulan. Telat 5 menit
        // tiga kali tidak sama dengan telat 15 menit sekali — tiernya beda.
        foreach ($late as $attendance) {
            $result = $this->rules->calculate(
                RuleType::Late,
                $attendance->work_date,
                (int) $attendance->late_minutes,
                $baseSalary,
                $workingDays,
            );

            $total += $result['amount'];

            if ($result['amount'] > 0) {
                $breakdown[] = [
                    'date' => $attendance->work_date->toDateString(),
                    'minutes' => $attendance->late_minutes,
                    'amount' => $result['amount'],
                    'rule' => $result['snapshot'],
                ];
            }
        }

        if ($total <= 0) {
            return;
        }

        $this->addItem(
            $payslip,
            'deduction',
            'Potongan Terlambat (' . $late->count() . 'x)',
            $late->count(),
            0,
            $total,
            $sort,
            ['breakdown' => $breakdown],
            'late',
        );
    }

    protected function addEarlyLeaveDeduction(Payslip $payslip, $attendances, PayrollPeriod $period, int $baseSalary, int $workingDays, int $sort): void
    {
        $early = $attendances->filter(fn ($a) => $a->early_leave_minutes > 0);

        if ($early->isEmpty()) {
            return;
        }

        $total = 0;

        foreach ($early as $attendance) {
            $total += $this->rules->calculate(
                RuleType::EarlyLeave,
                $attendance->work_date,
                (int) $attendance->early_leave_minutes,
                $baseSalary,
                $workingDays,
            )['amount'];
        }

        if ($total <= 0) {
            return;
        }

        $this->addItem($payslip, 'deduction', 'Potongan Pulang Cepat (' . $early->count() . 'x)', $early->count(), 0, $total, $sort, [], 'early_leave');
    }

    protected function addAbsentDeduction(Payslip $payslip, int $absentDays, PayrollPeriod $period, int $baseSalary, int $workingDays, int $sort): void
    {
        if ($absentDays <= 0) {
            return;
        }

        $result = $this->rules->calculate(RuleType::Absent, $period->end_date, $absentDays, $baseSalary, $workingDays);

        if ($result['amount'] <= 0) {
            return;
        }

        $this->addItem($payslip, 'deduction', "Potongan Alpha ({$absentDays} hari)", $absentDays, 0, $result['amount'], $sort, ['rule' => $result['snapshot']], 'absent');
    }

    protected function addBonuses(Payslip $payslip, Employee $employee, PayrollPeriod $period, int $sort): void
    {
        ManualPayrollEntry::query()
            ->where('employee_id', $employee->id)
            ->where('payroll_period_id', $period->id)
            ->bonus()
            ->get()
            ->each(function (ManualPayrollEntry $entry) use ($payslip, $sort) {
                $this->addItem($payslip, 'earning', 'Bonus: ' . $entry->reason, 1, $entry->amount, $entry->amount, $sort, [], 'manual', $entry->id);
            });
    }

    protected function addManualDeductions(Payslip $payslip, Employee $employee, PayrollPeriod $period, int $sort): void
    {
        ManualPayrollEntry::query()
            ->where('employee_id', $employee->id)
            ->where('payroll_period_id', $period->id)
            ->deduction()
            ->with('deductionType')
            ->get()
            ->each(function (ManualPayrollEntry $entry) use ($payslip, $sort) {
                $label = ($entry->deductionType?->name ?? 'Potongan') . ': ' . $entry->reason;
                $this->addItem($payslip, 'deduction', $label, 1, $entry->amount, $entry->amount, $sort, [], 'manual', $entry->id);
            });
    }

    protected function addCashAdvance(Payslip $payslip, Employee $employee, PayrollPeriod $period, int $sort): void
    {
        CashAdvanceInstallment::query()
            ->whereHas('cashAdvance', fn ($q) => $q->where('employee_id', $employee->id)->where('status', 'disbursed'))
            ->where('payroll_period_id', $period->id)
            ->where('status', 'scheduled')
            ->get()
            ->each(function (CashAdvanceInstallment $cicilan) use ($payslip, $sort) {
                $item = $this->addItem(
                    $payslip,
                    'deduction',
                    "Kasbon cicilan ke-{$cicilan->sequence}",
                    1,
                    $cicilan->amount,
                    $cicilan->amount,
                    $sort,
                    [],
                    'cash_advance',
                    $cicilan->id,
                );

                $cicilan->update(['status' => 'deducted', 'payslip_item_id' => $item->id]);
            });
    }

    /**
     * Penyesuaian dari periode lalu (I-12).
     *
     * Koreksi yang datang setelah periode dikunci tidak membuka kunci apa pun —
     * selisihnya muncul di sini, dengan penjelasan periode asalnya.
     */
    protected function addAdjustments(Payslip $payslip, Employee $employee, PayrollPeriod $period, int $sort): void
    {
        PayrollAdjustment::query()
            ->where('employee_id', $employee->id)
            ->where('applied_period_id', $period->id)
            ->with('originPeriod')
            ->get()
            ->each(function (PayrollAdjustment $adj) use ($payslip, $sort) {
                $this->addItem(
                    $payslip,
                    $adj->amount >= 0 ? 'earning' : 'deduction',
                    'Penyesuaian periode ' . ($adj->originPeriod?->code ?? '-') . ': ' . $adj->reason,
                    1,
                    abs($adj->amount),
                    abs($adj->amount),
                    $sort,
                    [],
                    'adjustment',
                    $adj->id,
                );
            });
    }

    protected function addBpjs(Payslip $payslip, PayrollPeriod $period, int $baseSalary, int $sort): void
    {
        $ruleSet = $this->rules->ruleSet(RuleType::Bpjs, $period->end_date);

        if ($ruleSet === null) {
            return;
        }

        foreach ($ruleSet->tiers as $tier) {
            $amount = (int) round($baseSalary * (float) $tier->value / 100);

            if ($amount <= 0) {
                continue;
            }

            $this->addItem(
                $payslip,
                'statutory',
                $tier->label ?? 'BPJS',
                1,
                $amount,
                $amount,
                $sort,
                ['rule' => $tier->toSnapshot()],
                'bpjs',
                $tier->id,
            );
        }
    }

    protected function addItem(
        Payslip $payslip,
        string $category,
        string $label,
        float $qty,
        int $rate,
        int $amount,
        int $sort,
        array $snapshot = [],
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): PayslipItem {
        return $payslip->items()->create([
            'salary_component_id' => SalaryComponent::where('code', $snapshot['component'] ?? '')->value('id'),
            'category' => $category,
            'label' => $label,
            'qty' => $qty,
            'rate' => $rate,
            'amount' => $amount,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'rule_snapshot' => $snapshot ?: null,
            'sort_order' => $sort,
        ]);
    }

    /** @return array<string, mixed> */
    protected function ruleSnapshot(PayrollPeriod $period): array
    {
        $snapshot = [];

        foreach (RuleType::cases() as $type) {
            $ruleSet = $this->rules->ruleSet($type, $period->end_date);
            $snapshot[$type->value] = $ruleSet?->only(['id', 'name', 'effective_from']);
        }

        return $snapshot;
    }
}
