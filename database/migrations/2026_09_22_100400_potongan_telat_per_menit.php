<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Potongan telat = Rp 1.000 × menit (keputusan pemilik, 22 September 2026),
 * menggantikan tier per blok 10 menit yang dipasang langsung di server.
 * Toleransi 10 menit tetap berlaku di depannya (AttendanceComputer).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('rule_sets')->where('type', 'late')->pluck('id') as $id) {
            DB::table('rule_tiers')->where('rule_set_id', $id)->delete();
            DB::table('rule_tiers')->insert([
                'rule_set_id' => $id,
                'min_value' => 1,
                'max_value' => null,
                'unit' => 'minute',
                'calc_type' => 'per_minute',
                'value' => 1000,
                'label' => 'Rp1.000 per menit keterlambatan',
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Tier sebelumnya adalah edit langsung di server; tidak ada yang dipulihkan.
    }
};
