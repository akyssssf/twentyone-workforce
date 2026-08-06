<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Libur yang berlaku untuk semua orang: hari raya, tanggal merah, atau
     * hari kafe tutup.
     *
     * Berbeda dari off_days di employees yang sifatnya mingguan dan per orang.
     */
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();

            $table->date('date')->unique();
            $table->string('name');

            // Dibedakan supaya laporan bisa memisahkan "kafe memang tutup"
            // dari "tanggal merah tapi kafe tetap buka".
            $table->boolean('is_closed')->default(true);

            $table->timestamps();

            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
