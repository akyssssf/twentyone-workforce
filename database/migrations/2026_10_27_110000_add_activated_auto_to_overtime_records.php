<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda: kodenya dipakai OTOMATIS oleh sistem, bukan dimasukkan orangnya.
 *
 * Dipakai halaman Lembur karyawan untuk menampilkan "lembur ini tercatat
 * otomatis" — pemberitahuan yang tidak lewat WhatsApp melainkan terlihat di
 * portal. activated_by saja tidak cukup membedakan, karena admin juga bisa
 * mengaktifkan atas nama orangnya (lembur:tugaskan --aktifkan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_records', function (Blueprint $table) {
            $table->boolean('activated_auto')->default(false)->after('activated_by');
        });
    }

    public function down(): void
    {
        Schema::table('overtime_records', function (Blueprint $table) {
            $table->dropColumn('activated_auto');
        });
    }
};
