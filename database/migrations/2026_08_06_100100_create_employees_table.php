<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            // Mapping ke PIN / user id yang terdaftar di mesin Fingerspot.
            // Mesin mengirim PIN sebagai string, jadi disimpan string supaya
            // "007" dan "7" tidak tertukar.
            $table->string('pin_device', 32)->unique();

            $table->string('name');
            $table->string('phone', 32)->nullable();

            // Karyawan tanpa shift dianggap belum dijadwalkan, bukan error.
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();

            $table->unsignedBigInteger('base_salary')->default(0);
            $table->boolean('is_active')->default(true);
            $table->date('joined_at')->nullable();
            $table->timestamps();

            $table->index('is_active');
            $table->index('shift_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
