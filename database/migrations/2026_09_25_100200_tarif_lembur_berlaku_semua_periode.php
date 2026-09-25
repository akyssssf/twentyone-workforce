<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tarif lembur berlaku untuk SEMUA periode, bukan cuma sejak 22 September.
 *
 * Keputusan pemilik (25 September): lembur bulan-bulan sebelumnya juga
 * dihitung dengan rumus jam × (gaji pokok ÷ hari kerja ÷ 10), lalu terbit
 * sebagai Slip Bonus.
 *
 * Aman untuk arsip 2026-09 yang sudah dibayar: bonus ada di luar take home
 * pay, jadi angka gaji di slip itu tidak bergeser sama sekali — yang bertambah
 * cuma dokumen bonusnya. Aturan alpha dan pulang cepat TIDAK ikut dimundurkan,
 * karena keduanya memang tidak dipotong di gajian 21 September.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('rule_sets')
            ->where('type', 'overtime')
            ->update(['effective_from' => '2026-01-01']);
    }

    public function down(): void
    {
        DB::table('rule_sets')
            ->where('type', 'overtime')
            ->update(['effective_from' => '2026-09-22']);
    }
};
