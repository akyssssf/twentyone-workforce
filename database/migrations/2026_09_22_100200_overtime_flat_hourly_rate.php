<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lembur dibayar 1× tarif per jam, semua jam sama.
 *
 * Tier bawaan lama (jam pertama 1,5×, berikutnya 2×) adalah asumsi "pola
 * umum" dari seeder, bukan keputusan kafe. Rumus yang ditetapkan pemilik:
 * tarif per jam = gaji pokok ÷ hari kerja ÷ 10, lalu dikalikan jam lembur.
 *
 * Diubah di tempat, bukan ditutup-dan-buka-baru, karena belum ada slip
 * gaji yang terbit memakai tier lama — tidak ada riwayat yang perlu dijaga.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ruleSetIds = DB::table('rule_sets')->where('type', 'overtime')->pluck('id');

        foreach ($ruleSetIds as $id) {
            DB::table('rule_tiers')->where('rule_set_id', $id)->delete();
            DB::table('rule_tiers')->insert([
                'rule_set_id' => $id,
                'min_value' => 1,
                'max_value' => null,
                'unit' => 'hour',
                'calc_type' => 'hourly_multiplier',
                'value' => 1.0,
                'label' => 'Lembur per jam (1× tarif per jam)',
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Tier lama adalah asumsi seeder; tidak ada yang perlu dipulihkan.
    }
};
