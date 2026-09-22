<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menyusul pergeseran periode (2026-09 sampai 21 Sep, 2026-10 mulai 22 Sep):
 * aturan alpha, pulang cepat, dan tarif lembur harus mulai berlaku 22 Sep,
 * bukan 21 — kalau tidak, potongan alpha ikut masuk ke arsip September
 * (dicari pada end_date periode = 21 Sep) padahal slip yang dibayar tidak
 * memotong alpha.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('rule_sets')
            ->whereIn('type', ['absent', 'early_leave', 'overtime'])
            ->whereDate('effective_from', '2026-09-21')
            ->update(['effective_from' => '2026-09-22']);
    }

    public function down(): void
    {
        DB::table('rule_sets')
            ->whereIn('type', ['absent', 'early_leave', 'overtime'])
            ->whereDate('effective_from', '2026-09-22')
            ->update(['effective_from' => '2026-09-21']);
    }
};
