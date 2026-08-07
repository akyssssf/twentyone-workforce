<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemberitahuan boleh tanpa akun penerima.
 *
 * Sejak WhatsApp jadi kanal sungguhan, ada dua penerima sah yang tidak punya
 * baris di tabel users:
 *
 *   - karyawan yang belum dibuatkan akun tapi nomornya sudah ada;
 *   - nomor admin di setelan, yang dipakai pemilik kafe tanpa akun karyawan.
 *
 * Memaksa user_id terisi berarti pesan ke mereka gagal disimpan — dan yang
 * gagal disimpan tidak punya jejak sama sekali, justru kebalikan dari tujuan
 * pola outbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Baris tanpa penerima dibuang dulu, kalau tidak kolomnya tidak bisa
        // dikembalikan jadi NOT NULL.
        \Illuminate\Support\Facades\DB::table('notifications')->whereNull('user_id')->delete();

        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
