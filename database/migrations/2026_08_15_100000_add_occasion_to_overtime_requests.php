<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Untuk apa lemburnya: menggantikan rekan, atau acara yang berdiri sendiri.
 *
 * Sebelum ini setiap lembur dianggap menutup posisi orang lain sehingga
 * pengganti wajib ditunjuk. Live music dan nobar tidak menggantikan siapa pun,
 * dan memaksanya lewat kolom pengganti membuat admin menuliskan nama yang
 * sebenarnya tidak sedang digantikan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table) {
            // Baris lama semuanya penggantian — itu satu-satunya bentuk yang
            // pernah bisa dibuat, jadi bawaannya aman diisikan mundur.
            $table->string('occasion', 24)->default('pengganti')->after('shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->dropColumn('occasion');
        });
    }
};
