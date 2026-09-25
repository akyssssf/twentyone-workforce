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
use App\Support\Settings;
use Illuminate\Support\Collection;
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

    /** Kategori tempat bonus ditaruh: 'bonus' (dibayar terpisah) atau 'earning' (digabung ke gaji). */
    protected function kategoriBonus(): string
    {
        return Settings::bool('payroll.bonus_terpisah', true) ? 'bonus' : 'earning';
    }

    /**
     * @param  string  $trigger  'manual' (ditekan manusia) atau 'otomatis' (estimasi harian cron)
     */
    public function generate(PayrollPeriod $period, string $trigger = 'manual'): PayrollRun
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
            'trigger' => $trigger,
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
                ->get()

                // Akun yang tidak diabsen DAN tidak punya gaji (akun test,
                // admin tanpa gaji di sistem) tidak diberi slip. Yang diabsen
                // tapi gajinya belum diatur TETAP diberi slip — slip Rp 0
                // adalah cara paling keras untuk memberi tahu bahwa ada yang
                // terlewat, dan halaman payroll menandainya.
                ->reject(fn (Employee $e) => ! $e->tracks_attendance && $e->baseSalaryOn($period->end_date) <= 0)
                ->values();

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

            // Estimasi harian yang sudah digantikan tidak punya nilai bukti —
            // isinya cuma tangkapan absensi sampai hari itu. Dibersihkan
            // supaya tabel slip tidak menumpuk 30 versi sebulan. Run manual
            // tidak disentuh.
            if ($trigger === 'otomatis') {
                $period->runs()
                    ->where('trigger', 'otomatis')
                    ->where('status', 'superseded')
                    ->where('id', '!=', $run->id)
                    ->each(fn (PayrollRun $lama) => $lama->delete());
            }

            AuditLogger::record('payroll.generated', $period, [], [
                'version' => $version,
                'trigger' => $trigger,
                'employees' => $employees->count(),
                'total' => $total,
            ]);
        } catch (\Throwable $e) {
            // Run yang gagal di tengah tidak boleh meninggalkan separuh slip;
            // yang tersisa cuma catatan run-nya beserta pesan errornya.
            $run->payslips()->delete();

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

        // Toleransi dibaca dari DETIK, bukan dari late_minutes yang sudah
        // dinolkan AttendanceComputer. Dua alasan: baris rekap yang dihitung
        // sebelum toleransi ada masih menyimpan menitnya, dan slip harus bisa
        // bilang "3 kali datang lewat, semuanya dalam toleransi".
        $toleransi = Settings::int('attendance.late_tolerance_minutes');
        $telatDipotong = $attendances->filter(fn ($a) => $a->late_seconds > $toleransi * 60);
        $telatDimaafkan = $attendances->filter(fn ($a) => $a->late_seconds > 0 && $a->late_seconds <= $toleransi * 60);

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
                'late_count' => $telatDipotong->count(),
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
        $this->addLateDeduction($payslip, $telatDipotong, $period, $baseSalary, $workingDays, $sort++);
        $this->addEarlyLeaveDeduction($payslip, $attendances, $period, $baseSalary, $workingDays, $sort++);
        $this->addAbsentDeduction($payslip, $attendances, $period, $baseSalary, $workingDays, $sort++);
        $this->addManualDeductions($payslip, $employee, $period, $sort++);
        $this->addCashAdvance($payslip, $employee, $period, $sort++);

        // --- Potongan wajib ---
        $this->addBpjs($payslip, $period, $baseSalary, $sort++);

        // --- Dasar perhitungan (informasi, bukan uang) ---
        $this->addBasis($payslip, $baseSalary, $workingDays, $toleransi, $telatDimaafkan, $sort);

        $items = $payslip->items()->get();

        $earning = (int) $items->where('category', 'earning')->sum('amount');
        $deduction = (int) $items->where('category', 'deduction')->sum('amount');
        $statutory = (int) $items->where('category', 'statutory')->sum('amount');

        // Bonus punya kategori sendiri dan TIDAK masuk take home pay: uangnya
        // diserahkan terpisah, jadi angka di slip gaji harus sama dengan yang
        // benar-benar diterima sebagai gaji.
        $bonus = (int) $items->where('category', 'bonus')->sum('amount');

        $payslip->update([
            'total_earning' => $earning,
            'total_deduction' => $deduction,
            'total_statutory' => $statutory,
            'total_bonus' => $bonus,
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

    /**
     * Bonus lembur: tiap hari lembur dihitung sendiri lalu dijumlahkan.
     *
     * Per hari, bukan dari total menit sebulan, supaya slip bisa menunjukkan
     * "5 Sep: 2j 30m = Rp 28.845" dan karyawan bisa mencocokkannya dengan
     * ingatannya sendiri. Rumus tarifnya ada di RuleResolver::hourlyRate().
     */
    protected function addOvertime(Payslip $payslip, Employee $employee, PayrollPeriod $period, int $baseSalary, int $workingDays, int $sort): int
    {
        $records = OvertimeRecord::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$period->start_date, $period->end_date])
            ->confirmed()
            ->where('payable_minutes', '>', 0)
            ->orderBy('work_date')
            ->get();

        if ($records->isEmpty()) {
            return 0;
        }

        $minutes = 0;
        $total = 0;
        $rincian = [];

        foreach ($records as $record) {
            $result = $this->rules->overtimePay($record->work_date, (int) $record->payable_minutes, $baseSalary, $workingDays);

            $minutes += (int) $record->payable_minutes;
            $total += $result['amount'];

            $rincian[] = [
                'date' => $record->work_date->toDateString(),
                'minutes' => (int) $record->payable_minutes,
                'amount' => $result['amount'],
                'note' => $record->note,
            ];
        }

        // Tidak ada tarif lembur yang berlaku di tanggal-tanggal itu (periode
        // sebelum aturan lembur dipasang): jamnya tetap tercatat di slip
        // sebagai keterangan, tapi bukan uang — dibayar di luar payroll.
        if ($total <= 0) {
            $this->addItem(
                $payslip,
                'info',
                'Lembur ' . $this->jam($minutes) . ': dibayar terpisah, tidak termasuk take home pay',
                round($minutes / 60, 2),
                0,
                0,
                $sort,
                ['rincian' => $rincian],
                'overtime',
            );

            return $minutes;
        }

        $this->addItem(
            $payslip,
            $this->kategoriBonus(),
            'Bonus Lembur ' . $this->jam($minutes),
            round($minutes / 60, 2),
            $this->rules->hourlyRate($baseSalary, $workingDays),
            $total,
            $sort,
            ['rincian' => $rincian],
            'overtime',
        );

        return $minutes;
    }

    /** @param  Collection<int, Attendance>  $late  baris yang telatnya SUDAH lewat toleransi */
    protected function addLateDeduction(Payslip $payslip, Collection $late, PayrollPeriod $period, int $baseSalary, int $workingDays, int $sort): void
    {
        if ($late->isEmpty()) {
            return;
        }

        $total = 0;
        $rincian = [];

        // Dihitung per kejadian, bukan dari total menit sebulan. Telat 15
        // menit tiga kali tidak sama dengan telat 45 menit sekali — tiernya
        // beda. Menit diambil dari detik supaya baris rekap yang dihitung
        // sebelum toleransi ada pun tetap benar.
        foreach ($late->sortBy('work_date') as $attendance) {
            $menit = (int) ceil($attendance->late_seconds / 60);

            $result = $this->rules->calculate(RuleType::Late, $attendance->work_date, $menit, $baseSalary, $workingDays);

            $total += $result['amount'];

            $rincian[] = [
                'date' => $attendance->work_date->toDateString(),
                'minutes' => $menit,
                'amount' => $result['amount'],
                'rule' => $result['label'],
            ];
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
            ['rincian' => $rincian],
            'late',
        );
    }

    protected function addEarlyLeaveDeduction(Payslip $payslip, Collection $attendances, PayrollPeriod $period, int $baseSalary, int $workingDays, int $sort): void
    {
        $early = $attendances->filter(fn ($a) => $a->early_leave_minutes > 0)->sortBy('work_date');

        if ($early->isEmpty()) {
            return;
        }

        $total = 0;
        $rincian = [];

        foreach ($early as $attendance) {
            $result = $this->rules->calculate(
                RuleType::EarlyLeave,
                $attendance->work_date,
                (int) $attendance->early_leave_minutes,
                $baseSalary,
                $workingDays,
            );

            $total += $result['amount'];

            $rincian[] = [
                'date' => $attendance->work_date->toDateString(),
                'minutes' => (int) $attendance->early_leave_minutes,
                'amount' => $result['amount'],
                'rule' => $result['label'],
            ];
        }

        if ($total <= 0) {
            return;
        }

        $this->addItem(
            $payslip,
            'deduction',
            'Potongan Pulang Cepat (' . $early->count() . 'x)',
            $early->count(),
            0,
            $total,
            $sort,
            ['rincian' => $rincian],
            'early_leave',
        );
    }

    protected function addAbsentDeduction(Payslip $payslip, Collection $attendances, PayrollPeriod $period, int $baseSalary, int $workingDays, int $sort): void
    {
        $alpha = $attendances->where('status', AttendanceStatus::Alpha)->sortBy('work_date');

        if ($alpha->isEmpty()) {
            return;
        }

        $result = $this->rules->calculate(RuleType::Absent, $period->end_date, $alpha->count(), $baseSalary, $workingDays);

        if ($result['amount'] <= 0) {
            return;
        }

        // Satu tarif untuk semua hari, jadi rinciannya cukup tanggalnya —
        // nominal per hari = total ÷ jumlah hari.
        $perHari = intdiv($result['amount'], $alpha->count());

        $this->addItem(
            $payslip,
            'deduction',
            'Potongan Alpha (' . $alpha->count() . ' hari)',
            $alpha->count(),
            $perHari,
            $result['amount'],
            $sort,
            [
                'rule' => $result['snapshot'],
                'rincian' => $alpha->map(fn ($a) => [
                    'date' => $a->work_date->toDateString(),
                    'amount' => $perHari,
                ])->values()->all(),
            ],
            'absent',
        );
    }

    /**
     * Baris informasi: angka yang dipakai menghitung, supaya slip bisa
     * dicek ulang dengan kalkulator, bukan dipercaya begitu saja.
     *
     * Kategori 'info' tidak ikut dijumlahkan ke pendapatan/potongan.
     */
    protected function addBasis(Payslip $payslip, int $baseSalary, int $workingDays, int $toleransi, Collection $telatDimaafkan, int $sort): void
    {
        $tarifHarian = $workingDays > 0 ? intdiv($baseSalary, $workingDays) : 0;
        $jamPerHari = max(1, Settings::int('payroll.hours_per_day', 10));

        $this->addItem($payslip, 'info', "Hari kerja terjadwal: {$workingDays} hari", $workingDays, 0, 0, $sort++, [], 'basis');
        $this->addItem($payslip, 'info', 'Tarif harian: gaji pokok ÷ ' . $workingDays . ' hari', 1, $tarifHarian, 0, $sort++, [], 'basis');
        $this->addItem($payslip, 'info', "Tarif per jam: tarif harian ÷ {$jamPerHari} jam", 1, $this->rules->hourlyRate($baseSalary, $workingDays), 0, $sort++, [], 'basis');

        if ($toleransi > 0) {
            $this->addItem(
                $payslip,
                'info',
                "Toleransi telat {$toleransi} menit: " . $telatDimaafkan->count() . 'x datang lewat masih dalam toleransi, tidak dipotong',
                $telatDimaafkan->count(),
                0,
                0,
                $sort++,
                [
                    'rincian' => $telatDimaafkan->sortBy('work_date')->map(fn ($a) => [
                        'date' => $a->work_date->toDateString(),
                        'seconds' => (int) $a->late_seconds,
                    ])->values()->all(),
                ],
                'basis',
            );
        }
    }

    protected function jam(int $menit): string
    {
        $jam = intdiv($menit, 60);
        $sisa = $menit % 60;

        return $sisa === 0 ? "{$jam} jam" : "{$jam} jam {$sisa} menit";
    }

    protected function addBonuses(Payslip $payslip, Employee $employee, PayrollPeriod $period, int $sort): void
    {
        ManualPayrollEntry::query()
            ->where('employee_id', $employee->id)
            ->where('payroll_period_id', $period->id)
            ->bonus()
            ->get()
            ->each(function (ManualPayrollEntry $entry) use ($payslip, $sort) {
                $this->addItem($payslip, $this->kategoriBonus(), 'Bonus: ' . $entry->reason, 1, $entry->amount, $entry->amount, $sort, [], 'manual', $entry->id);
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

    /**
     * Cicilan kasbon yang jatuh tempo di periode ini.
     *
     * Cicilan yang SUDAH ditandai terpotong oleh run sebelumnya ikut ditarik
     * lagi: hitung ulang membuat slip baru, dan kasbon tidak boleh hilang
     * dari slip hanya karena payroll dihitung dua kali. Penandanya dipindah
     * ke baris slip yang baru.
     */
    protected function addCashAdvance(Payslip $payslip, Employee $employee, PayrollPeriod $period, int $sort): void
    {
        CashAdvanceInstallment::query()
            ->with('cashAdvance')
            ->whereHas('cashAdvance', fn ($q) => $q->where('employee_id', $employee->id)->where('status', 'disbursed'))
            ->where('payroll_period_id', $period->id)
            ->whereIn('status', ['scheduled', 'deducted'])
            ->orderBy('cash_advance_id')
            ->orderBy('sequence')
            ->get()
            ->each(function (CashAdvanceInstallment $cicilan) use ($payslip, $sort) {
                $kasbon = $cicilan->cashAdvance;
                $label = $kasbon->installments_count > 1
                    ? "Kasbon cicilan ke-{$cicilan->sequence} dari {$kasbon->installments_count}"
                    : 'Kasbon';

                $item = $this->addItem(
                    $payslip,
                    'deduction',
                    $label,
                    1,
                    $cicilan->amount,
                    $cicilan->amount,
                    $sort,
                    [
                        'kasbon' => [
                            'id' => $kasbon->id,
                            'tanggal' => $kasbon->disbursed_at?->toDateString(),
                            'total' => $kasbon->amount,
                            'alasan' => $kasbon->reason,
                        ],
                    ],
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
