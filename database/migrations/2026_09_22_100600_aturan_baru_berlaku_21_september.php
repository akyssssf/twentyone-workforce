<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gajian 21 September (periode 2026-09) sudah dibayar dengan slip buatan
 * luar: hanya kasbon dan telat yang dipotong; alpha tidak dipotong; lembur
 * dibayar terpisah di luar THP. Supaya arsip di sistem sama persis dengan
 * yang dibayar, aturan alpha, pulang cepat, dan tarif lembur baru berlaku
 * mulai 21 September — periode 2026-10 ke depan. Aturan telat tetap dari
 * awal tahun karena memang dipakai di slip itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('rule_sets')
            ->whereIn('type', ['absent', 'early_leave', 'overtime'])
            ->whereDate('effective_from', '<', '2026-09-21')
            ->update(['effective_from' => '2026-09-21']);
    }

    public function down(): void
    {
        DB::table('rule_sets')
            ->whereIn('type', ['absent', 'early_leave', 'overtime'])
            ->update(['effective_from' => '2026-01-01']);
    }
};
