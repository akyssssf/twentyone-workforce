<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jam shift yang berlaku per RENTANG TANGGAL.
 *
 * Jam di tabel shifts berlaku global: mengubahnya untuk "mulai besok" ikut
 * mengubah semua tanggal yang sudah lewat begitu dihitung ulang — dan cron
 * menghitung ulang dua hari terakhir tiap 15 menit. Pulang cepat dan lembur di
 * bulan yang belum digaji ikut bergeser tanpa ada yang sadar.
 *
 * Tabel ini menjawab "jam shift X pada tanggal Y itu berapa" dengan riwayat:
 * satu baris per perubahan, berlaku dari effective_from sampai effective_to
 * (null = sampai ada perubahan berikutnya). Tanggal yang tidak tercakup baris
 * mana pun memakai jam master seperti biasa.
 *
 * Berbeda dari start_time_override di roster_assignments, yang menempel
 * per-ORANG per-tanggal: ini menempel ke shift-nya, jadi berlaku untuk siapa
 * pun yang dijadwalkan di situ, tanpa peduli kapan rosternya diisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_time_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['shift_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_time_overrides');
    }
};
