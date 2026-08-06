<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 2 — Perluasan master yang sudah ada.
 *
 * Menyentuh tabel berisi data (employees 18 baris, shifts 2 baris), tapi hanya
 * menambah dan mengganti nama kolom. Tidak ada yang dihapus di batch ini.
 * Backfill datanya menyusul di migration berikutnya, sengaja dipisah supaya
 * kalau backfill gagal, struktur tetap utuh dan bisa diulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->string('code', 16)->nullable()->after('id');
            $table->boolean('crosses_midnight')->default(false)->after('end_time');

            $table->unsignedSmallInteger('break_minutes')->default(60);

            // D-02: istirahat dibayar, jadi shift 09-17 bernilai 8 jam kerja.
            $table->boolean('is_break_paid')->default(true);

            // Pindahan dari config/attendance.php. Ditaruh di shift supaya
            // shift malam bisa punya toleransi berbeda dari shift pagi tanpa
            // mengubah kode.
            $table->unsignedTinyInteger('window_before_hours')->default(4);
            $table->unsignedTinyInteger('window_after_hours')->default(4);

            // Jeda setelah jam pulang sebelum menit dihitung sebagai kandidat
            // lembur. Nol berarti mulai dihitung persis di jam pulang.
            $table->unsignedSmallInteger('overtime_starts_after_minutes')->default(0);

            $table->string('color', 7)->nullable();
            $table->softDeletes();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained()->nullOnDelete();

            // Nomor induk internal, beda dari PIN mesin. PIN bisa berpindah
            // orang; nomor induk tidak pernah.
            $table->string('employee_no', 32)->nullable()->after('branch_id');

            $table->string('email', 120)->nullable();

            // active | resigned | suspended
            $table->string('employment_status', 16)->default('active');
            $table->date('resigned_at')->nullable();

            $table->softDeletes();

            $table->index('employment_status');
        });

        // Rename: shift_id dan off_days turun pangkat jadi PREFERENSI.
        // Setelah roster ada, keduanya bukan lagi jawaban atas "hari ini dia
        // shift apa" — itu tugas roster_assignments. Namanya diubah supaya
        // tidak ada yang keliru memakainya sebagai fakta jadwal.
        Schema::table('employees', function (Blueprint $table) {
            $table->renameColumn('shift_id', 'default_shift_id');
            $table->renameColumn('off_days', 'preferred_off_days');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamp('last_login_at')->nullable();

            // Akun karyawan dibuat manager dengan password sementara.
            $table->boolean('must_change_password')->default(false);

            $table->softDeletes();
        });

        Schema::table('holidays', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained()->nullOnDelete();

            // Membedakan tanggal merah nasional dari "kafe tutup karena
            // renovasi". Keduanya libur, tapi laporannya beda arti.
            $table->boolean('is_national')->default(true);
        });

        // Pemetaan PIN mesin ke karyawan, BERPERIODE.
        //
        // Ini memperbaiki lubang paling berbahaya di sistem lama: kalau
        // karyawan resign lalu PIN-nya dipakai karyawan baru, seluruh riwayat
        // absensi lama ikut berpindah ke orang baru tanpa error apa pun.
        // Dengan valid_from/valid_to, pencocokan scan memakai pemetaan yang
        // berlaku pada TANGGAL SCAN, bukan pemetaan hari ini.
        Schema::create('employee_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('cloud_id', 64);
            $table->string('pin', 32);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('note', 255)->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Jalur pencocokan scan: cari PIN ini di mesin ini yang berlaku
            // pada tanggal sekian.
            $table->index(['cloud_id', 'pin', 'valid_from']);
            $table->index(['employee_id', 'valid_from']);
        });

        // Kompetensi lintas divisi.
        //
        // Pivot, bukan employees.division_id, karena kafe kekurangan 3-4 orang
        // dari kebutuhan sehingga merangkap divisi pasti terjadi. Ini yang
        // memungkinkan sistem menjawab "shift malam kurang 1 kasir, siapa yang
        // bisa mengisi?".
        Schema::create('employee_divisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('division_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);

            // 1 = bisa membantu, 2 = cakap, 3 = mahir.
            $table->unsignedTinyInteger('competency_level')->default(2);

            $table->timestamps();

            $table->unique(['employee_id', 'division_id']);
            $table->index(['division_id', 'competency_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_divisions');
        Schema::dropIfExists('employee_devices');

        Schema::table('holidays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn('is_national');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn(['last_login_at', 'must_change_password', 'deleted_at']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->renameColumn('default_shift_id', 'shift_id');
            $table->renameColumn('preferred_off_days', 'off_days');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['employee_no', 'email', 'employment_status', 'resigned_at', 'deleted_at']);
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn([
                'code', 'crosses_midnight', 'break_minutes', 'is_break_paid',
                'window_before_hours', 'window_after_hours',
                'overtime_starts_after_minutes', 'color', 'deleted_at',
            ]);
        });
    }
};
