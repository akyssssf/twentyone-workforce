<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bedakan run yang ditekan manusia dari run estimasi harian oleh cron.
 *
 * Estimasi berjalan dihitung tiap pagi; tanpa penanda ini, dalam sebulan
 * ada 30 versi yang semuanya "tersimpan sebagai bukti" padahal cuma
 * tangkapan harian. Run otomatis yang sudah digantikan boleh dibersihkan;
 * run manual tetap dijaga seperti semula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            // manual | otomatis
            $table->string('trigger', 16)->default('manual')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn('trigger');
        });
    }
};
