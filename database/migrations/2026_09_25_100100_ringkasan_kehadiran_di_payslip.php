<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Angka ringkasan kehadiran yang dicetak di slip.
 *
 * Izin dan sakit dipisah (sebelumnya menyatu di leave_days) dan total menit
 * telat dibekukan bersama slip, supaya baris "Terlambat (>10 mnt): 5x (81
 * mnt)" tidak perlu dihitung ulang dari absensi — absensi boleh berubah
 * setelah slip terbit, slipnya tidak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->unsignedSmallInteger('permit_days')->default(0)->after('leave_days');
            $table->unsignedSmallInteger('sick_days')->default(0)->after('permit_days');
            $table->unsignedInteger('late_minutes')->default(0)->after('late_count');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['permit_days', 'sick_days', 'late_minutes']);
        });
    }
};
