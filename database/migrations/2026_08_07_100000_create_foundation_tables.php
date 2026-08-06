<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 1 — Fondasi.
 *
 * Semuanya tabel baru, tidak menyentuh data yang sudah ada, jadi batch ini
 * tidak punya risiko sama sekali.
 *
 * Beberapa tabel digabung dalam satu file migration karena mereka satu paket
 * konseptual dan selalu dibuat/dihapus bersama (rule_sets tanpa rule_tiers
 * tidak ada gunanya). Yang berdiri sendiri tetap dipisah.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cabang. Satu baris sekarang, tapi kolomnya ditanam sejak awal karena
        // menambahkan branch_id ke tabel yang sudah berisi payroll adalah
        // migrasi besar yang tidak mau dikerjakan siapa pun.
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name', 100);
            $table->text('address')->nullable();
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('divisions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 64);

            // Dipakai grid roster. Terlihat sepele, tapi tanpa warna, jadwal
            // 18 orang x 31 hari tidak terbaca sama sekali.
            $table->string('color', 7)->nullable();

            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        // Setelan operasional yang boleh diubah manager tanpa deploy.
        //
        // Key-value dipilih karena daftar setelan pasti terus bertambah
        // (min_rest_hours, max_consecutive_days, ...) dan kolom-per-setelan
        // berarti migrasi tiap kali. Kehilangan type-safety DB ditebus kolom
        // `type` + casting terpusat di Settings service.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('group', 32);
            $table->string('key', 64);
            $table->json('value');
            $table->string('type', 16)->default('string');
            $table->string('label', 120)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'key']);
            $table->index('group');
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 64);

            // is_paid menentukan potongan gaji, deducts_balance menentukan
            // kuota. Dua hal berbeda: sakit dengan surat dokter dibayar tapi
            // tidak memotong jatah cuti tahunan.
            $table->boolean('is_paid')->default(true);
            $table->boolean('deducts_balance')->default(true);
            $table->boolean('requires_evidence')->default(false);

            $table->unsignedSmallInteger('max_days_per_request')->default(30);
            $table->unsignedSmallInteger('min_notice_days')->default(0);
            $table->decimal('default_entitlement_days', 5, 1)->default(0);

            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 64);

            // earning | deduction | statutory
            $table->string('category', 16);

            // fixed | per_day | per_hour | percent
            $table->string('calc_type', 16)->default('fixed');

            // Belum dipakai. Ada supaya PPh 21 bisa ditambahkan nanti tanpa
            // mengubah struktur tabel apa pun.
            $table->boolean('is_taxable')->default(true);

            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('deduction_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 64);

            // is_system = dihitung otomatis dari absensi (telat, alpha).
            // Selain itu manager yang menginput nominalnya sendiri.
            $table->boolean('is_system')->default(false);

            $table->unsignedBigInteger('default_amount')->default(0);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        // Aturan yang menyentuh uang. Tidak pernah diedit di tempat: mengubah
        // tarif berarti menutup rule_set lama (effective_to) dan membuat yang
        // baru, supaya slip gaji bulan lalu tidak ikut berubah.
        Schema::create('rule_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            // late | early_leave | overtime | absent | bpjs
            $table->string('type', 24);

            $table->string('name', 100);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['type', 'effective_from', 'effective_to']);
        });

        Schema::create('rule_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_set_id')->constrained()->cascadeOnDelete();

            // Batas inklusif. max_value null = tak terbatas ke atas.
            $table->integer('min_value');
            $table->integer('max_value')->nullable();

            // minute | hour | day
            $table->string('unit', 16)->default('minute');

            // flat            : value = rupiah
            // daily_rate      : value = pengali tarif harian
            // hourly_multiplier: value = pengali tarif per jam (lembur)
            // percent_of_base : value = persen dari gaji pokok (BPJS)
            $table->string('calc_type', 24)->default('flat');

            $table->decimal('value', 12, 2)->default(0);

            // Ikut disalin ke slip gaji, jadi karyawan membaca "Telat 11-30
            // menit", bukan kode internal.
            $table->string('label', 64)->nullable();

            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['rule_set_id', 'min_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_tiers');
        Schema::dropIfExists('rule_sets');
        Schema::dropIfExists('deduction_types');
        Schema::dropIfExists('salary_components');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('divisions');
        Schema::dropIfExists('branches');
    }
};
