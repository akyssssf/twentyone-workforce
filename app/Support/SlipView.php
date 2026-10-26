<?php

namespace App\Support;

use App\Models\Payslip;
use App\Models\PayslipItem;
use Illuminate\Support\Carbon;

/**
 * Bahan tampilan slip yang dipakai bersama oleh halaman di layar, halaman
 * cetak, dan dokumen PDF untuk unduhan massal.
 *
 * Tiga tempat itu dulu menyusun angka sendiri-sendiri, dan itulah cara slip
 * di layar dan slip di PDF lama-lama berbeda. Di sini angka dan kalimatnya
 * disusun SEKALI; tiap tampilan tinggal menggambarnya.
 */
class SlipView
{
    /**
     * @return array<string, mixed>
     */
    public static function data(Payslip $payslip): array
    {
        $payslip->loadMissing(['items', 'employee', 'run.period']);

        $snap = $payslip->employee_snapshot ?? [];
        $items = $payslip->items;
        $toleransi = ToleransiTelat::menit();

        // Slip yang terbit sebelum kolom late_minutes ada menyimpan 0; angkanya
        // masih utuh di rincian baris potongannya.
        $telatMenit = (int) $payslip->late_minutes;

        if ($telatMenit === 0 && $payslip->late_count > 0) {
            $telatMenit = (int) collect($items->firstWhere('source_type', 'late')?->rule_snapshot['rincian'] ?? [])->sum('minutes');
        }

        $cuti = max(0, $payslip->leave_days - $payslip->permit_days - $payslip->sick_days);

        $kehadiran = [
            'Kehadiran' => $payslip->present_days.' Hari',
            'Terlambat (>'.$toleransi.' mnt)' => $payslip->late_count.'x ('.$telatMenit.' mnt)',
            'Izin' => $payslip->permit_days.' Hari',
            'Sakit' => $payslip->sick_days.' Hari',
            'Alpha (Tanpa Keterangan)' => $payslip->absent_days.' Hari',
            'Total Lembur' => Durasi::menit((int) $payslip->overtime_minutes).($payslip->overtime_minutes > 0 ? ' *' : ''),
        ];

        if ($cuti > 0) {
            $kehadiran['Cuti'] = $cuti.' Hari';
        }

        $info = $items->where('category', 'info');

        $keterangan = [];

        if (! $info->contains(fn ($i) => str_contains($i->label, 'Toleransi telat'))) {
            $keterangan[] = "Keterlambatan sampai dengan {$toleransi} menit ditoleransi (tidak dipotong).";
        }

        foreach ($info as $item) {
            $keterangan[] = $item->label.($item->rate > 0 ? ': Rp '.number_format($item->rate, 0, ',', '.') : '');
        }

        if ($payslip->overtime_minutes > 0) {
            $keterangan[] = '* Jam lembur dibayarkan terpisah dan tidak termasuk dalam Take Home Pay di atas.';
        }

        $barisLembur = $items->where('source_type', 'overtime');
        $lembur = $barisLembur->first();

        return [
            'payslip' => $payslip,
            'nama' => $snap['name'] ?? $payslip->employee?->name ?? '-',
            'pin' => $snap['pin'] ?? '-',
            'noInduk' => $snap['employee_no'] ?? '-',
            'divisi' => $snap['division'] ?? '-',
            'periode' => $payslip->run->period->label(),
            'dibayar' => $payslip->run->period->pay_date->translatedFormat('d M Y'),
            'dibayarPanjang' => $payslip->run->period->pay_date->translatedFormat('d F Y'),

            'pendapatan' => static::baris($items->where('category', 'earning')),
            'potongan' => static::baris($items->whereIn('category', ['deduction', 'statutory'])),
            'bonus' => static::baris($items->where('category', 'bonus')),

            'totalPendapatan' => (int) $payslip->total_earning,
            'totalPotongan' => (int) $payslip->total_deduction + (int) $payslip->total_statutory,
            'totalBonus' => (int) $payslip->total_bonus,
            'thp' => (int) $payslip->take_home_pay,

            'kehadiran' => $kehadiran,
            'keterangan' => $keterangan,
            'adaBonus' => $items->where('category', 'bonus')->isNotEmpty(),
            'adaLembur' => $payslip->overtime_minutes > 0,
            'jamLembur' => Durasi::menit((int) $payslip->overtime_minutes),

            // Tarif yang BENAR-BENAR dipakai saat slip dihitung (sudah dibekukan
            // di baris lembur), bukan tarif hari ini.
            'tarifJam' => (int) ($lembur?->rule_snapshot['tarif_dasar'] ?? $lembur?->rate ?? 0),
            'hariLembur' => (int) $barisLembur->sum(fn ($i) => count($i->rule_snapshot['rincian'] ?? [])),
            'hariKerja' => (int) $payslip->scheduled_days,
            'jamPerHari' => max(1, Settings::int('payroll.hours_per_day', 10)),

            'namaBerkasGaji' => NamaBerkasSlip::untuk($payslip, 'SLIP_GAJI'),
            'namaBerkasBonus' => NamaBerkasSlip::untuk($payslip, 'SLIP_BONUS'),
        ];
    }

    /**
     * Satu baris slip beserta rinciannya, dalam bentuk yang siap digambar:
     * tiap rincian satu baris pendek (tanggal · lama · rupiah), bukan satu
     * kalimat panjang yang harus dibaca menyamping.
     *
     * @param  iterable<int, PayslipItem>  $items
     * @return list<array<string, mixed>>
     */
    protected static function baris(iterable $items): array
    {
        $hasil = [];

        foreach ($items as $item) {
            $detail = [];

            foreach ($item->rule_snapshot['rincian'] ?? [] as $r) {
                $keterangan = trim((string) ($r['note'] ?? ($r['rule'] ?? '')));

                $detail[] = [
                    'tanggal' => Carbon::parse($r['date'])->translatedFormat('D, d M'),
                    'lama' => isset($r['minutes']) ? Durasi::menit((int) $r['minutes']) : '',
                    'keterangan' => $keterangan,
                    'jumlah' => isset($r['amount']) ? (int) $r['amount'] : null,
                ];
            }

            $hasil[] = [
                'label' => $item->label,
                'rumus' => ($item->rate > 0 && $item->qty != 1)
                    ? rtrim(rtrim(number_format((float) $item->qty, 2, ',', '.'), '0'), ',').' × Rp '.number_format($item->rate, 0, ',', '.')
                    : '',
                'jumlah' => (int) $item->amount,
                'detail' => $detail,
                'sumber' => $item->source_type,
            ];
        }

        return $hasil;
    }
}
