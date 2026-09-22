<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Slip gajian 21 September (buatan luar) menghitung 21 Agt s/d 21 Sep —
 * satu hari lebih panjang dari periode baku 21–20. Supaya arsip 2026-09 di
 * sistem sama persis dengan yang dibayar, dan 21 September tidak terhitung
 * dua kali, batasnya digeser satu kali ini saja:
 *
 *   2026-09 : 21 Agt – 21 Sep
 *   2026-10 : 22 Sep – 20 Okt
 *
 * Periode berikutnya kembali ke pola 21–20 dari PayrollPeriodFactory.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('payroll_periods')->where('code', '2026-09')->update(['end_date' => '2026-09-21']);
        DB::table('payroll_periods')->where('code', '2026-10')->update(['start_date' => '2026-09-22']);
    }

    public function down(): void
    {
        DB::table('payroll_periods')->where('code', '2026-09')->update(['end_date' => '2026-09-20']);
        DB::table('payroll_periods')->where('code', '2026-10')->update(['start_date' => '2026-09-21']);
    }
};
