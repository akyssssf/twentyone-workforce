<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dua kebijakan baru dari kafe.
 *
 * 1. Setiap pengajuan wajib menyebut PENGGANTI.
 *
 *    Sebelumnya pengganti hanya dicatat sebagai catatan bebas di pengajuan
 *    cuti, dan tidak ada apa pun yang memaksa diisi. Akibatnya cuti bisa
 *    disetujui tanpa ada yang tahu siapa yang menutup shift-nya — dan itu baru
 *    ketahuan pada hari H, saat sudah tidak ada waktu mencari orang.
 *
 *    Sekarang kolomnya foreign key ke employees, wajib ada sebelum manajer
 *    bisa menyetujui, dan rekan yang ditunjuk harus mengonfirmasi lebih dulu.
 *
 * 2. Lembur ditunjuk lewat KODE RAHASIA.
 *
 *    Manajer menunjuk satu orang, orang itu menerima kode, dan hanya dia yang
 *    bisa mengaktifkan lembur tersebut. Kode membuat "siapa yang berhak lembur
 *    malam ini" jadi sesuatu yang dipegang orangnya, bukan kesepakatan lisan
 *    yang bisa diaku siapa saja saat pengesahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            // Siapa yang menggantikan selama pengaju tidak ada.
            //
            // Nullable di tingkat kolom karena pengajuan bisa disimpan sebagai
            // draf lebih dulu; kewajibannya ditegakkan di service saat
            // pengajuan dikirim dan saat manajer menyetujui.
            $table->foreignId('substitute_employee_id')->nullable()->after('employee_id')
                ->constrained('employees')->nullOnDelete();

            $table->timestamp('substitute_accepted_at')->nullable();
            $table->timestamp('substitute_rejected_at')->nullable();
            $table->text('substitute_note')->nullable();

            $table->index('substitute_employee_id');
        });

        Schema::table('overtime_requests', function (Blueprint $table) {
            // Kode yang dipegang karyawan yang ditunjuk. Pendek dan mudah
            // dibacakan lewat telepon, tapi cukup acak untuk tidak ditebak
            // dalam satu malam.
            $table->string('secret_code', 12)->nullable();

            $table->index('secret_code');
        });

        Schema::table('overtime_records', function (Blueprint $table) {
            // Bukti bahwa orang yang ditunjuk benar-benar mengambil lembur ini.
            // Tanpa aktivasi, lembur tetap tidak dibayar walau ada scan —
            // sama seperti lembur tanpa approval sama sekali.
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('overtime_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('activated_by');
            $table->dropColumn('activated_at');
        });

        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->dropIndex(['secret_code']);
            $table->dropColumn('secret_code');
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('substitute_employee_id');
            $table->dropColumn(['substitute_accepted_at', 'substitute_rejected_at', 'substitute_note']);
        });
    }
};
