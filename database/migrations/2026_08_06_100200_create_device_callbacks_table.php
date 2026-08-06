<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Arsip mentah SEMUA callback yang masuk, termasuk yang gagal diparse dan
     * yang bukan tipe attlog. Tabel ini append-only dan tidak pernah dihapus
     * oleh proses olahan, supaya kalau ada sengketa data selalu bisa diputar
     * ulang dari sini.
     */
    public function up(): void
    {
        Schema::create('device_callbacks', function (Blueprint $table) {
            $table->id();

            // Semuanya nullable: kalau payload rusak, baris tetap harus masuk.
            $table->string('cloud_id', 64)->nullable();

            // attlog | get_userinfo | get_userid_list | set_time | set_userinfo
            // | delete_userinfo, atau apa pun yang dikirim mesin.
            $table->string('type', 64)->nullable();

            // Fingerspot TIDAK mengirim trans_id untuk push spontan (attlog).
            // Kolom ini baru terisi untuk callback balasan panggilan API.
            $table->string('trans_id', 64)->nullable();

            $table->json('payload');

            $table->string('ip', 45)->nullable();

            // parsed = sudah dipindahkan ke attendance_logs.
            $table->boolean('parsed')->default(false);
            $table->text('parse_error')->nullable();

            $table->timestamp('received_at');
            $table->timestamps();

            // Antrian kerja parser: cari yang belum diparse, urut waktu terima.
            $table->index(['parsed', 'received_at']);
            $table->index(['cloud_id', 'received_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_callbacks');
    }
};
