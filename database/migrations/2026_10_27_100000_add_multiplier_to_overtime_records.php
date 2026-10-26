<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengali lembur per catatan (1 = tarif biasa, 2 = dua kali lipat).
 *
 * Disimpan di CATATAN LEMBURNYA, bukan dengan menggandakan payable_minutes:
 * menit yang dibayar harus tetap menit yang sungguh dikerjakan, supaya slip
 * bisa bilang "5 jam 12 menit" dan bukan "10 jam 24 menit" untuk lembur yang
 * lamanya 5 jam 12 menit. Pengalinya baru dipakai saat payroll menghitung rupiah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_records', function (Blueprint $table) {
            $table->decimal('multiplier', 3, 1)->default(1.0)->after('payable_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('overtime_records', function (Blueprint $table) {
            $table->dropColumn('multiplier');
        });
    }
};
