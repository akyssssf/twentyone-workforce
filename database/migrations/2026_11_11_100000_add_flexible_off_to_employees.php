<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Karyawan yang menentukan sendiri hari liburnya: roster dibuat
            // penuh, hari tanpa scan dicatat Libur, bukan Alpha.
            $table->boolean('flexible_off')->default(false)->after('tracks_attendance');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('flexible_off');
        });
    }
};
