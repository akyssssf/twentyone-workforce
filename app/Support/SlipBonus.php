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
        $lembur = $payslip->items->firstWhere('source_type', 'overtime');
        $rincian = $lembur?->rule_snapshot['rincian'] ?? [];

        return [
            'payslip' => $payslip,

            // Tarif yang BENAR-BENAR dipakai saat slip dihitung, bukan tarif
            // hari ini: rate baris lemburnya sudah dibekukan di situ.
            'tarifJam' => (int) ($lembur?->rate ?? 0),
            'hariLembur' => count($rincian),
            'jamPerHari' => max(1, Settings::int('payroll.hours_per_day', 10)),
        ];
    }
}
