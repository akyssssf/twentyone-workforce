<?php

namespace App\Services\Rules;

use App\Enums\RuleType;
use App\Models\Branch;
use App\Models\RuleSet;
use App\Models\RuleTier;
use Illuminate\Support\Carbon;

/**
 * Menemukan aturan yang berlaku pada satu tanggal, lalu memilih tier yang cocok.
 *
 * Seluruh kerumitan "aturan mana yang dipakai" berhenti di kelas ini. Pemanggil
 * cukup bertanya "telat 23 menit pada 5 Agustus dipotong berapa" dan menerima
 * jawabannya beserta salinan aturannya untuk dibekukan ke slip gaji.
 *
 * Kenapa harus per tanggal, bukan "aturan yang aktif sekarang": kalau manager
 * menaikkan tarif potongan hari ini lalu payroll bulan lalu digenerate ulang,
 * hasilnya harus tetap memakai tarif yang berlaku saat itu. Tanpa ini,
 * pertanyaan "kenapa potongan Juli berubah" tidak akan pernah bisa dijawab.
 */
class RuleResolver
{
    /** @var array<string, ?RuleSet> */
    protected array $cache = [];

    public function ruleSet(RuleType $type, Carbon $date, ?int $branchId = null): ?RuleSet
    {
        $branchId ??= Branch::current()->id;
        $key = $type->value . '|' . $date->toDateString() . '|' . $branchId;

        return $this->cache[$key] ??= RuleSet::query()
            ->with('tiers')
            ->where('branch_id', $branchId)
            ->where('type', $type->value)
            ->effectiveOn($date)
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Tier yang cocok untuk sebuah nilai terukur (menit telat, jam lembur, dst).
     */
    public function tier(RuleType $type, Carbon $date, int $value): ?RuleTier
    {
        $ruleSet = $this->ruleSet($type, $date);

        if ($ruleSet === null) {
            return null;
        }

        return $ruleSet->tiers->first(fn (RuleTier $tier) => $tier->matches($value));
    }

    /**
     * Hitung nominal rupiah dari sebuah pelanggaran/hak.
     *
     * @param  int  $value      Menit telat, menit pulang cepat, hari alpha, dst.
     * @param  int  $baseSalary Gaji pokok sebulan, dipakai calc_type non-flat.
     * @param  int  $workingDays Hari kerja terjadwal dalam periode, untuk tarif harian.
     * @return array{amount: int, tier: ?RuleTier, snapshot: ?array, label: ?string}
     */
    public function calculate(
        RuleType $type,
        Carbon $date,
        int $value,
        int $baseSalary = 0,
        int $workingDays = 26,
    ): array {
        $none = ['amount' => 0, 'tier' => null, 'snapshot' => null, 'label' => null];

        if ($value <= 0) {
            return $none;
        }

        $tier = $this->tier($type, $date, $value);

        if ($tier === null) {
            return $none;
        }

        $dailyRate = $workingDays > 0 ? intdiv($baseSalary, $workingDays) : 0;

        // Tarif per jam mengikuti jam kerja terjadwal, bukan konstanta.
        // Istirahat ikut dibayar (D-02), jadi shift 8 jam bernilai 8 jam.
        $hourlyRate = $workingDays > 0 ? intdiv($dailyRate, 8) : 0;

        $amount = match ($tier->calc_type) {
            'flat' => (int) round((float) $tier->value),
            'daily_rate' => (int) round($dailyRate * (float) $tier->value),
            'hourly_multiplier' => (int) round($hourlyRate * (float) $tier->value * $value),
            'percent_of_base' => (int) round($baseSalary * (float) $tier->value / 100),
            default => 0,
        };

        return [
            'amount' => max(0, $amount),
            'tier' => $tier,
            'snapshot' => $tier->toSnapshot(),
            'label' => $tier->label,
        ];
    }

    /**
     * Lembur dihitung bertingkat per jam: jam pertama satu pengali, jam
     * berikutnya pengali lain. Jadi tidak bisa memakai calculate() yang
     * mencocokkan satu tier saja.
     *
     * @return array{amount: int, breakdown: array<int, array<string, mixed>>}
     */
    public function overtimePay(Carbon $date, int $minutes, int $baseSalary, int $workingDays = 26): array
    {
        if ($minutes <= 0 || $workingDays <= 0) {
            return ['amount' => 0, 'breakdown' => []];
        }

        $ruleSet = $this->ruleSet(RuleType::Overtime, $date);

        if ($ruleSet === null) {
            return ['amount' => 0, 'breakdown' => []];
        }

        $hourlyRate = intdiv(intdiv($baseSalary, $workingDays), 8);
        $hours = $minutes / 60;
        $total = 0;
        $breakdown = [];

        // Jalan per jam supaya tiap jam bisa jatuh ke tier berbeda.
        for ($hour = 1; $hour <= ceil($hours); $hour++) {
            $portion = min(1.0, $hours - ($hour - 1));
            $tier = $ruleSet->tiers->first(fn (RuleTier $t) => $t->matches($hour));

            if ($tier === null) {
                continue;
            }

            $amount = (int) round($hourlyRate * (float) $tier->value * $portion);
            $total += $amount;

            $breakdown[] = [
                'hour' => $hour,
                'portion' => round($portion, 2),
                'multiplier' => (float) $tier->value,
                'amount' => $amount,
                'snapshot' => $tier->toSnapshot(),
            ];
        }

        return ['amount' => $total, 'breakdown' => $breakdown];
    }
}
