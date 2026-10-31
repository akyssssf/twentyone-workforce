<?php

namespace App\Console\Commands;

use App\Models\ManualPayrollEntry;
use App\Models\OvertimeRecord;
use App\Models\PayslipItem;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Payroll\PayrollPeriodFactory;
use App\Services\Rules\RuleResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Bayar lembur khusus (General Cleaning, pengali > 1) sebagai bonus di slip
 * periode gajian yang SUDAH berjalan, bukan menunggu payroll periode tanggal kerjanya.
 *
 * Nominalnya sama persis dengan hitungan payroll biasa (tarif per jam × menit
 * × pengali). Catatan lemburnya ditandai paid_in_period supaya payroll periode
 * berikutnya tidak membayarnya lagi.
 */
class GeneralOvertimeBonus extends Command
{
    protected $signature = 'lembur:bonus-umum
                            {tanggal : Tanggal lembur (YYYY-MM-DD)}
                            {--bulan= : Periode gajian tempat bonus dibayar, mis. 2026-10 (wajib)}
                            {--pin=* : Hanya PIN ini (bisa diulang)}
                            {--simpan : Simpan. Tanpa ini hanya pratinjau}';

    protected $description = 'Bayar lembur khusus (pengali > 1) sebagai bonus di periode gajian yang sudah berjalan';

    public function handle(PayrollPeriodFactory $periods, RuleResolver $rules): int
    {
        try {
            $tanggal = Carbon::createFromFormat('Y-m-d', (string) $this->argument('tanggal'))->startOfDay();
        } catch (\Throwable) {
            $this->error('Tanggal harus berformat YYYY-MM-DD.');

            return self::FAILURE;
        }

        if (! preg_match('/^(\d{4})-(\d{2})$/', (string) $this->option('bulan'), $m)) {
            $this->error('Isi --bulan seperti 2026-10 (periode gajian tempat bonus dibayar).');

            return self::FAILURE;
        }

        $period = $periods->forMonth((int) $m[1], (int) $m[2]);

        if ($period->isLocked()) {
            $this->error("Periode {$period->code} sudah dikunci; bonus tidak bisa ditambah di sana.");

            return self::FAILURE;
        }

        $records = OvertimeRecord::query()
            ->with('employee')
            ->whereDate('work_date', $tanggal)
            ->confirmed()
            ->where('multiplier', '>', 1)
            ->where('payable_minutes', '>', 0)
            ->whereNull('paid_in_period')
            ->when($this->option('pin'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->whereIn('pin_device', array_map('strval', $this->option('pin')))))
            ->get()
            ->sortBy(fn (OvertimeRecord $r) => $r->employee->name);

        if ($records->isEmpty()) {
            $this->warn('Tidak ada lembur khusus yang belum dibayar pada tanggal itu.');

            return self::SUCCESS;
        }

        $baris = [];
        $siapkan = [];
        $total = 0;

        foreach ($records as $r) {
            $emp = $r->employee;
            $gaji = $emp->baseSalaryOn($period->end_date);
            $hari = $this->hariKerja($emp->id, $period->id);

            if ($gaji <= 0 || $hari === null) {
                $this->warn("{$emp->name}: gaji atau hari kerja di periode {$period->code} tidak ditemukan; dilewati. Hitung dulu: php artisan payroll:hitung {$period->code}");

                continue;
            }

            $pengali = (float) $r->multiplier;
            $uang = (int) round($rules->overtimePay($r->work_date, (int) $r->payable_minutes, $gaji, $hari)['amount'] * $pengali);

            if ($uang <= 0) {
                $this->warn("{$emp->name}: nominal 0 (tidak ada aturan lembur berlaku); dilewati.");

                continue;
            }

            $alasan = sprintf(
                'Lembur General Cleaning %s (%s ×%s)',
                $r->work_date->translatedFormat('j M Y'),
                $this->jam((int) $r->payable_minutes),
                rtrim(rtrim(number_format($pengali, 1, ',', ''), '0'), ','),
            );

            $total += $uang;
            $siapkan[] = [$r, $uang, $alasan];
            $baris[] = [$emp->pin_device, $emp->name, $this->jam((int) $r->payable_minutes), '×'.rtrim(rtrim(number_format($pengali, 1, ',', ''), '0'), ','), 'Rp '.number_format($uang, 0, ',', '.')];
        }

        if ($siapkan === []) {
            return self::FAILURE;
        }

        $this->info("Bonus lembur khusus {$tanggal->translatedFormat('l, j F Y')} → periode {$period->code} ({$period->rentangHitung()})");
        $this->table(['PIN', 'Nama', 'Lama', 'Pengali', 'Bonus'], $baris);
        $this->line(count($siapkan).' orang · total Rp '.number_format($total, 0, ',', '.'));

        if (! $this->option('simpan')) {
            $this->warn('Belum disimpan. Ulangi dengan --simpan.');

            return self::SUCCESS;
        }

        $admin = User::where('role', 'admin')->first() ?? User::first();

        if ($admin !== null) {
            Auth::login($admin);
        }

        foreach ($siapkan as [$r, $uang, $alasan]) {
            $entry = ManualPayrollEntry::create([
                'employee_id' => $r->employee_id,
                'payroll_period_id' => $period->id,
                'entry_type' => 'bonus',
                'amount' => $uang,
                'reason' => $alasan,
                'created_by' => $admin?->id,
            ]);

            $r->update(['paid_in_period' => $period->code]);

            AuditLogger::record('payroll.manual_entry', $entry, [], [
                'employee' => $r->employee->name, 'amount' => $uang, 'reason' => $alasan,
            ]);
        }

        $this->info('Tersimpan. Hitung ulang supaya masuk slip: php artisan payroll:hitung '.$period->code);

        return self::SUCCESS;
    }

    /** Hari kerja terjadwal yang dipakai payroll periode ini (dibaca dari slip yang sudah ada). */
    protected function hariKerja(int $employeeId, int $periodId): ?int
    {
        $qty = PayslipItem::query()
            ->where('source_type', 'basis')
            ->where('label', 'like', 'Hari kerja terjadwal%')
            ->whereHas('payslip', fn ($q) => $q->where('employee_id', $employeeId)
                ->whereHas('run', fn ($r) => $r->where('payroll_period_id', $periodId)))
            ->latest('id')
            ->value('qty');

        return $qty === null ? null : (int) $qty;
    }

    protected function jam(int $menit): string
    {
        $j = intdiv($menit, 60);
        $s = $menit % 60;

        return $s === 0 ? "{$j}j" : "{$j}j {$s}m";
    }
}
