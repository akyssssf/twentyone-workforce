<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hasil olahan, satu baris per karyawan per hari. Tabel ini sepenuhnya
     * turunan: boleh dihapus dan digenerate ulang dari attendance_logs kapan
     * saja tanpa kehilangan apa pun.
     */
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // Shift yang berlaku saat baris ini dihitung. Disalin, bukan cuma
            // direferensikan lewat employee, supaya kalau karyawan pindah
            // shift bulan depan, rekap bulan lalu tidak ikut berubah.
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();

            $table->date('work_date');

            // Batas on time hasil gabungan work_date + shifts.start_time.
            // Ikut disalin dengan alasan yang sama seperti shift_id.
            $table->timestamp('scheduled_in')->nullable();

            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();

            // Selisih check_in_at terhadap scheduled_in. Nol kalau tidak telat.
            $table->unsignedInteger('late_seconds')->default(0);

            // Jumlah blok potongan hasil pembulatan late_seconds.
            $table->unsignedInteger('late_blocks')->default(0);

            // late_blocks * tarif per blok saat perhitungan dijalankan.
            // Disimpan, bukan dihitung ulang tiap dibaca, jadi mengubah tarif
            // di config tidak mengubah rekap lama dengan sendirinya. Tapi
            // menghitung ulang tanggal lama SETELAH tarif berubah akan
            // menimpanya dengan tarif baru.
            $table->unsignedBigInteger('deduction_amount')->default(0);

            // hadir | telat | alpha | libur
            $table->string('status', 16);

            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            // Satu karyawan hanya boleh punya satu baris per tanggal.
            $table->unique(['employee_id', 'work_date']);

            // Pola query rekap: semua karyawan pada satu tanggal / rentang.
            $table->index(['work_date', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
