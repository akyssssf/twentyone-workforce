<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // start_time = batas on time. Scan lewat jam ini dihitung telat.
            // Shift 1 -> 09:00:00, shift 2 -> 17:00:00.
            $table->time('start_time');
            $table->time('end_time');

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
