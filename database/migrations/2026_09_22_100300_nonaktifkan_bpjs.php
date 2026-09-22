<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kafe tidak memotong BPJS dari gaji (keputusan pemilik, 22 September 2026).
 *
 * Aturan BPJS 4% yang dipasang seeder adalah asumsi awal. Dinonaktifkan,
 * bukan dihapus: kalau suatu hari kafe mulai memotong BPJS, tinggal
 * diaktifkan lagi dengan tanggal berlaku baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('rule_sets')->where('type', 'bpjs')->update(['is_active' => false]);
    }

    public function down(): void
    {
        DB::table('rule_sets')->where('type', 'bpjs')->update(['is_active' => true]);
    }
};
