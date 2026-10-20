<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\ManualPayrollEntry;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Payroll\PayrollPeriodFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Catat bonus manual untuk satu orang di satu periode gajian.
 *
 * Untuk bonus yang tanggal kejadiannya SUDAH lewat dari periodenya — misalnya
 * lembur 4–5 September yang baru ketahuan belum dikonfirmasi saat gajian
 * Oktober. lembur:tugaskan tidak bisa dipakai: catatan lembur menempel ke
 * TANGGAL kerjanya, jadi masuk ke periode September (yang sudah dibayar),
 * bukan ke slip yang sedang dihitung.
 *
 * Jalur yang sama dengan formulir "Bonus & Potongan Manual" di halaman
 * Payroll, jadi hasilnya muncul sebagai satu baris di Slip Bonus.
 */
class RecordBonus extends Command
{
    protected $signature = 'bonus:catat
                            {pin : PIN karyawan}
                            {jumlah : Nominal rupiah, mis. 61600}
                            {--bulan= : Periode gajian, mis. 2026-10 (bawaan: periode yang mencakup hari ini)}
                            {--alasan= : Wajib. Tulis tanggal dan jamnya supaya terbaca di slip}
                            {--dry-run : Tampilkan saja, jangan simpan}';

    protected $description = 'Catat bonus manual untuk satu orang di satu periode gajian';

    public function handle(PayrollPeriodFactory $periods): int
    {
        $employee = Employee::where('pin_device', (string) $this->argument('pin'))->first();

        if ($employee === null) {
            $this->error("Tidak ada karyawan dengan PIN {$this->argument('pin')}.");

            return self::FAILURE;
        }

        $jumlah = (int) preg_replace('/\D+/', '', (string) $this->argument('jumlah'));

        if ($jumlah <= 0) {
            $this->error('Jumlah harus lebih dari nol.');

            return self::FAILURE;
        }

        $alasan = trim((string) $this->option('alasan'));

        if (mb_strlen($alasan) < 5) {
            $this->error('Isi --alasan (minimal 5 huruf). Bonus tanpa penjelasan tidak bisa dipertanggungjawabkan.');

            return self::FAILURE;
        }

        $bulan = $this->option('bulan');

        if ($bulan !== null && ! preg_match('/^(\d{4})-(\d{2})$/', (string) $bulan, $m)) {
            $this->error('Format --bulan harus seperti 2026-10.');

            return self::FAILURE;
        }

        $period = $bulan !== null
            ? $periods->forMonth((int) $m[1], (int) $m[2])
            : $periods->covering(now());

        if ($period->isLocked()) {
            $this->error("Periode {$period->code} sudah dikunci; bonus tidak bisa ditambah di sana.");

            return self::FAILURE;
        }

        // Pagar dobel: perintah yang sama dijalankan dua kali sudah pernah
        // membuat kasbon terpotong dua kali.
        $sudahAda = ManualPayrollEntry::query()
            ->where('employee_id', $employee->id)
            ->where('payroll_period_id', $period->id)
            ->where('entry_type', 'bonus')
            ->where('amount', $jumlah)
            ->where('reason', $alasan)
            ->exists();

        if ($sudahAda) {
            $this->error('Bonus yang sama persis (orang, nominal, alasan) sudah tercatat di periode ini. Tidak disimpan lagi.');

            return self::FAILURE;
        }

        $this->table(['PIN', 'Nama', 'Periode', 'Bonus', 'Alasan'], [[
            $employee->pin_device, $employee->name, $period->code,
            'Rp '.number_format($jumlah, 0, ',', '.'), $alasan,
        ]]);

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN: tidak ada yang disimpan.');

            return self::SUCCESS;
        }

        $admin = User::where('role', 'admin')->first() ?? User::first();

        if ($admin !== null) {
            Auth::login($admin);
        }

        $entry = ManualPayrollEntry::create([
            'employee_id' => $employee->id,
            'payroll_period_id' => $period->id,
            'entry_type' => 'bonus',
            'amount' => $jumlah,
            'reason' => $alasan,
            'created_by' => $admin?->id,
        ]);

        AuditLogger::record('payroll.manual_entry', $entry, [], [
            'employee' => $employee->name, 'amount' => $jumlah, 'reason' => $alasan,
        ]);

        $this->info("Bonus tersimpan. Hitung ulang supaya masuk slip: php artisan payroll:hitung {$period->code}");

        return self::SUCCESS;
    }
}
