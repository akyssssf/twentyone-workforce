<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tukar libur: dua orang bertukar hari libur.
 *
 * Ditumpangkan ke shift_swap_requests, bukan tabel baru, karena tukar libur
 * SECARA MEKANIS adalah dua kali tukar shift — isi baris roster kedua orang
 * ditukar di tanggal libur si A, lalu ditukar lagi di tanggal libur si B.
 * Mesin penukarnya sudah ada dan sudah menyelesaikan bagian tersulitnya
 * (menukar ISI baris, bukan kepemilikannya, karena SQLite tidak menunda
 * pengecekan constraint unik). Membuat tabel kedua berarti menyalin pelajaran
 * itu ke tempat kedua yang akan menyimpang diam-diam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_swap_requests', function (Blueprint $table) {
            // 'shift' = tukar shift satu tanggal (perilaku lama).
            // 'libur' = tukar hari libur, memakai pasangan baris kedua.
            $table->string('kind', 16)->default('shift')->after('request_id');

            // Pasangan kedua: baris kedua orang di tanggal libur si REKAN.
            // nullOnDelete, bukan cascade — menghapus satu baris roster tidak
            // boleh ikut menghapus riwayat pengajuannya (lihat jebakan nomor 4
            // di CATATAN_SESI.md, kolom lama memakai cascade dan itu jebakan).
            $table->foreignId('requester_assignment_2_id')->nullable()
                ->after('partner_assignment_id')
                ->constrained('roster_assignments')->nullOnDelete();

            $table->foreignId('partner_assignment_2_id')->nullable()
                ->after('requester_assignment_2_id')
                ->constrained('roster_assignments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shift_swap_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requester_assignment_2_id');
            $table->dropConstrainedForeignId('partner_assignment_2_id');
            $table->dropColumn('kind');
        });
    }
};
