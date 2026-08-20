<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jam shift khusus untuk satu tanggal, menimpa jam di master shift.
 *
 * Jam di tabel shifts berlaku global: mengubahnya untuk satu hari akan ikut
 * mengubah semua tanggal, termasuk yang sudah lewat — dan cron yang menghitung
 * ulang dua hari terakhir tiap 15 menit akan memakai jam baru itu untuk
 * kemarin juga. Jadi "ubah lalu kembalikan" bukan pilihan yang aman.
 *
 * Ditaruh di roster_assignments, bukan bikin shift baru, supaya nama shift,
 * warna, dan perhitungan kuota tenaga tetap seperti biasa — yang berbeda cuma
 * jamnya, di tanggal itu saja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roster_assignments', function (Blueprint $table) {
            $table->time('start_time_override')->nullable()->after('shift_id');
            $table->time('end_time_override')->nullable()->after('start_time_override');
        });
    }

    public function down(): void
    {
        Schema::table('roster_assignments', function (Blueprint $table) {
            $table->dropColumn(['start_time_override', 'end_time_override']);
        });
    }
};
