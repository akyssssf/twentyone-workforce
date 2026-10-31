<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_records', function (Blueprint $table) {
            // Kode periode gajian (mis. 2026-10) yang sudah membayar lembur ini
            // sebagai bonus manual. Payroll periode tanggal kerjanya melewati yang berisi ini.
            $table->string('paid_in_period', 7)->nullable()->after('activated_auto');
        });
    }

    public function down(): void
    {
        Schema::table('overtime_records', function (Blueprint $table) {
            $table->dropColumn('paid_in_period');
        });
    }
};
