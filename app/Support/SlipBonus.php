<?php

namespace App\Support;

use App\Models\Payslip;

/**
 * Angka turunan untuk halaman Slip Bonus.
 *
 * Dipakai dua controller (manajer & karyawan) dengan hasil yang harus sama
 * persis — kalau dihitung dua kali di dua tempat, cepat atau lambat dua
 * halaman itu akan menampilkan angka yang berbeda untuk slip yang sama.
 *
 * @return array{payslip: Payslip, tarifJam: int, hariLembur: int, jamPerHari: int}
 */
class SlipBonus
{
    public static function data(Payslip $payslip): array
    {
        // Bisa lebih dari satu baris lembur (biasa dan berpengali, mis. ×2).
        $barisLembur = $payslip->items->where('source_type', 'overtime');
        $lembur = $barisLembur->first();

        return [
            'payslip' => $payslip,

            // Tarif yang BENAR-BENAR dipakai saat slip dihitung, bukan tarif
            // hari ini: rate baris lemburnya sudah dibekukan di situ.
            'tarifJam' => (int) ($lembur?->rule_snapshot['tarif_dasar'] ?? $lembur?->rate ?? 0),
            'hariLembur' => (int) $barisLembur->sum(fn ($i) => count($i->rule_snapshot['rincian'] ?? [])),
            'jamPerHari' => max(1, Settings::int('payroll.hours_per_day', 10)),
        ];
    }
}
