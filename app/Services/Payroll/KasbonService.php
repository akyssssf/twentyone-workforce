<?php

namespace App\Services\Payroll;

use App\Models\CashAdvance;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Kasbon: uang yang sudah diterima karyawan, dipotong dari gaji.
 *
 * Satu kasbon = satu baris cash_advances + satu cicilan per periode gajian.
 * Payroll menarik cicilan yang jatuh tempo SENDIRI saat menghitung — manajer
 * tidak perlu mengetik ulang potongan tiap bulan, dan kasbon yang dicicil
 * tiga kali tidak bisa terlewat di bulan ketiga.
 *
 * Uangnya dianggap sudah cair saat dicatat (status disbursed). Tidak ada
 * alur pengajuan/persetujuan: di kafe ini kasbon disepakati lisan dengan
 * pemilik, sistem cuma perlu memastikan potongannya benar-benar terjadi.
 */
class KasbonService
{
    public function __construct(protected PayrollPeriodFactory $periods) {}

    /**
     * @param  int  $cicilan  jumlah cicilan; sisa pembagian ditaruh di cicilan pertama
     * @param  string  $bulanPotong  kode periode gajian pertama tempat dipotong, "2026-10"
     */
    public function catat(
        Employee $employee,
        int $jumlah,
        string $bulanPotong,
        int $cicilan = 1,
        ?string $keterangan = null,
        ?Carbon $tanggal = null,
        ?int $olehUserId = null,
    ): CashAdvance {
        if ($jumlah <= 0) {
            throw new RuntimeException('Jumlah kasbon harus lebih dari nol.');
        }

        if ($cicilan < 1 || $cicilan > 12) {
            throw new RuntimeException('Jumlah cicilan harus 1 sampai 12.');
        }

        if (! preg_match('/^(\d{4})-(\d{2})$/', $bulanPotong, $m)) {
            throw new RuntimeException('Bulan potong harus berformat 2026-10.');
        }

        $pertama = Carbon::create((int) $m[1], (int) $m[2], 1)->startOfDay();
        $periodes = [];

        for ($i = 0; $i < $cicilan; $i++) {
            $bulan = $pertama->copy()->addMonthsNoOverflow($i);
            $periode = $this->periods->forMonth((int) $bulan->year, (int) $bulan->month);

            // Periode yang sudah dikunci gajinya sudah dibayar; potongan baru
            // tidak mungkin masuk ke sana.
            if ($periode->isLocked()) {
                throw new RuntimeException("Periode {$periode->code} sudah dikunci; kasbon tidak bisa dipotong di sana. Mulai dari bulan berikutnya.");
            }

            $periodes[] = $periode;
        }

        $perCicilan = intdiv($jumlah, $cicilan);
        $sisa = $jumlah - $perCicilan * $cicilan;

        return DB::transaction(function () use ($employee, $jumlah, $cicilan, $keterangan, $tanggal, $olehUserId, $periodes, $perCicilan, $sisa) {
            $kasbon = CashAdvance::create([
                'employee_id' => $employee->id,
                'amount' => $jumlah,
                'installments_count' => $cicilan,
                'reason' => trim((string) $keterangan) !== '' ? trim($keterangan) : 'Kasbon',
                'status' => 'disbursed',
                'approved_by' => $olehUserId,
                'disbursed_at' => ($tanggal ?? Carbon::today())->startOfDay(),
            ]);

            foreach ($periodes as $i => $periode) {
                $kasbon->installments()->create([
                    'payroll_period_id' => $periode->id,
                    'sequence' => $i + 1,
                    'amount' => $perCicilan + ($i === 0 ? $sisa : 0),
                    'status' => 'scheduled',
                ]);
            }

            AuditLogger::record('kasbon.recorded', $kasbon, [], [
                'employee' => $employee->name,
                'amount' => $jumlah,
                'installments' => $cicilan,
                'first_period' => $periodes[0]->code,
            ]);

            return $kasbon->load('installments');
        });
    }

    /**
     * Batalkan kasbon.
     *
     * Yang dibatalkan: cicilan yang belum terpotong, DAN cicilan yang sudah
     * masuk slip tapi periodenya belum disetujui — slip draf boleh berubah,
     * dan hitung ulang berikutnya akan menghapus potongannya. Cicilan di
     * periode yang sudah disetujui/dikunci tidak disentuh: uang itu sudah
     * dibayar, koreksinya lewat penyesuaian periode berikutnya.
     */
    public function batalkan(CashAdvance $kasbon, ?string $alasan = null): CashAdvance
    {
        $sudah = $this->cicilanTerkunci($kasbon)->count();

        DB::transaction(function () use ($kasbon, $sudah, $alasan) {
            $kasbon->installments()->where('status', 'scheduled')->update(['status' => 'skipped']);

            $kasbon->installments()
                ->where('status', 'deducted')
                ->whereNotIn('id', $this->cicilanTerkunci($kasbon)->pluck('id'))
                ->update(['status' => 'skipped', 'payslip_item_id' => null]);

            // Kalau belum ada yang terpotong sama sekali, kasbonnya memang
            // batal. Kalau sebagian sudah terpotong, sisanya dianggap dihapus
            // (written off), riwayat potongannya tetap ada.
            $kasbon->update(['status' => $sudah === 0 ? 'cancelled' : 'paid_off']);

            AuditLogger::record('kasbon.cancelled', $kasbon, [], ['reason' => $alasan, 'already_deducted' => $sudah]);
        });

        return $kasbon->fresh('installments');
    }

    /** Cicilan yang sudah terpotong di periode yang disetujui/dikunci: benar-benar sudah dibayar. */
    public function cicilanTerkunci(CashAdvance $kasbon)
    {
        return $kasbon->installments()
            ->where('status', 'deducted')
            ->whereHas('period', fn ($q) => $q->whereIn('status', ['approved', 'locked']))
            ->get();
    }

    /** Sisa yang belum terpotong dari seluruh kasbon aktif seorang karyawan. */
    public function sisaUntuk(Employee $employee): int
    {
        return (int) CashAdvance::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'disbursed')
            ->get()
            ->sum(fn (CashAdvance $k) => $k->remaining());
    }

    /** Periode gajian tempat kasbon yang dicatat hari ini akan dipotong pertama kali. */
    public function periodeBerikutnya(?Carbon $tanggal = null): PayrollPeriod
    {
        return $this->periods->covering($tanggal ?? Carbon::today());
    }
}
