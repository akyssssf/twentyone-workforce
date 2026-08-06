<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Bawaannya manager, bukan owner. Akun yang entah bagaimana
            // terbuat tanpa peran eksplisit sebaiknya jadi yang paling
            // terbatas, bukan yang paling berkuasa.
            $table->string('role', 16)->default('manager');

            // Akun yang dinonaktifkan tetap disimpan supaya jejak siapa
            // mengubah apa tidak putus.
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
