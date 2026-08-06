<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Hari libur mingguan tetap milik karyawan ini, disimpan sebagai
            // array nomor hari ala Carbon: 0=Minggu, 1=Senin, ... 6=Sabtu.
            //
            // Dipilih kolom JSON alih-alih tabel terpisah karena isinya paling
            // banyak tujuh angka per orang dan tidak pernah di-query sendiri,
            // selalu ikut karyawannya. Tabel tersendiri cuma menambah join
            // tanpa memberi apa pun.
            //
            // Null berarti "belum diatur", berbeda dari [] yang berarti
            // "memang tidak punya libur mingguan".
            $table->json('off_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('off_days');
        });
    }
};
