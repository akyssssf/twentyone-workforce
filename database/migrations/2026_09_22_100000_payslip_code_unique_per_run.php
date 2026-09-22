<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor slip unik PER RUN, bukan se-tabel.
 *
 * Nomor slip berbentuk SLIP-2026-09-013: periode + karyawan. Sengaja tidak
 * memuat versi, karena "hitung ulang" menghasilkan versi baru dari slip yang
 * sama — nomornya harus tetap. Tapi unique lama dipasang di kolom code saja,
 * jadi run kedua langsung menabrak run pertama pada karyawan pertama:
 *
 *   UNIQUE constraint failed: payslips.code
 *
 * Akibatnya payroll hanya pernah bisa dihitung SEKALI per periode; setiap
 * "Hitung ulang" gagal di karyawan pertama dan run-nya tercatat failed.
 * Unique yang benar: satu nomor per run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropUnique('payslips_code_unique');
            $table->unique(['payroll_run_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropUnique(['payroll_run_id', 'code']);
            $table->unique('code');
        });
    }
};
