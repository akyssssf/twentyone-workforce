<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sebagian shift tidak ingin jam-nya ditampilkan ke karyawan — Shift Middle
 * jam pastinya belum final dikonfirmasi ke bos, jadi menampilkannya di layar
 * karyawan berisiko dianggap jadwal resmi padahal masih bisa berubah.
 *
 * Kolom, bukan dikira dari nama shift: keputusan "tampilkan jam atau tidak"
 * milik pemilik kafe, bukan sesuatu yang ditebak dari kode/nama shift-nya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->boolean('show_hours')->default(true)->after('end_time');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('show_hours');
        });
    }
};
