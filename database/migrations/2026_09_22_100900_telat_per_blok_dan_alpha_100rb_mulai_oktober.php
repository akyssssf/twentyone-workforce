<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aturan periode Oktober ke depan (keputusan pemilik, 22 September 2026):
 *   telat : Rp 10.000 per blok 10 menit yang genap dilewati (15 menit =
 *           10.000, 23 = 20.000), setelah toleransi 10 menit
 *   alpha : Rp 100.000 per hari
 *
 * Aturan telat lama (Rp 1.000/menit) DITUTUP per 21 Sep, bukan diubah —
 * arsip September dibayar dengan tarif itu dan harus tetap bisa dihitung
 * ulang persis sama. Aturan alpha (berlaku 22 Sep, belum pernah dipakai)
 * cukup diganti tiernya.
 */
return new class extends Migration
{
    public function up(): void
    {
        $lateLama = DB::table('rule_sets')
            ->where('type', 'late')
            ->where('is_active', true)
            ->whereNull('effective_to')
            ->get();

        foreach ($lateLama as $rs) {
            DB::table('rule_sets')->where('id', $rs->id)->update(['effective_to' => '2026-09-21']);

            $baru = DB::table('rule_sets')->insertGetId([
                'branch_id' => $rs->branch_id,
                'type' => 'late',
                'name' => 'Potongan Terlambat per 10 menit',
                'effective_from' => '2026-09-22',
                'effective_to' => null,
                'is_active' => true,
                'note' => 'Rp 10.000 per blok 10 menit yang genap dilewati, setelah toleransi 10 menit',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('rule_tiers')->insert([
                'rule_set_id' => $baru,
                'min_value' => 1,
                'max_value' => null,
                'unit' => 'minute',
                'calc_type' => 'per_block',
                'value' => 10000,
                'label' => 'Rp10.000 per 10 menit keterlambatan',
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (DB::table('rule_sets')->where('type', 'absent')->pluck('id') as $id) {
            DB::table('rule_tiers')->where('rule_set_id', $id)->delete();
            DB::table('rule_tiers')->insert([
                'rule_set_id' => $id,
                'min_value' => 1,
                'max_value' => null,
                'unit' => 'day',
                'calc_type' => 'flat',
                'value' => 100000,
                'label' => 'Alpha Rp100.000 per hari',
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('rule_sets')->where('type', 'late')->whereDate('effective_from', '2026-09-22')->delete();
        DB::table('rule_sets')->where('type', 'late')->whereDate('effective_to', '2026-09-21')->update(['effective_to' => null]);
    }
};
