<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 4 — Absensi. Batch paling sensitif: attendances sudah berisi data.
 *
 * Urutan langkah di dalam restructure attendances TIDAK BOLEH ditukar. Di
 * SQLite, menghapus kolom memicu Laravel membangun ulang tabel. Kalau
 * penghapusan dilakukan sebelum unique key baru terpasang, tabel dibangun ulang
 * membawa struktur setengah jadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // webhook | sync | import
            $table->string('source', 16)->default('import');

            $table->string('file_path', 255)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_inserted')->default(0);
            $table->unsignedInteger('rows_duplicate')->default(0);
            $table->unsignedInteger('rows_failed')->default(0);
            $table->json('error_log')->nullable();

            $table->string('status', 16)->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            // Hasil pencocokan PIN -> karyawan lewat employee_devices yang
            // berlaku di tanggal scan.
            //
            // Ini kolom TURUNAN, bukan sumber kebenaran: `pin` mentah tidak
            // pernah disentuh, dan kolom ini boleh dihitung ulang kapan saja.
            // Nilai null justru berguna - satu query WHERE employee_id IS NULL
            // langsung memberi daftar scan dari PIN tak dikenal.
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();

            $table->index(['employee_id', 'scanned_at']);
        });

        // --- attendances: tambah dulu, hapus belakangan ---

        Schema::table('attendances', function (Blueprint $table) {
            $table->integer('shift_key')->default(0)->after('shift_id');

            $table->foreignId('roster_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('scheduled_out')->nullable()->after('scheduled_in');

            // Fakta, bukan uang. Nominal potongannya urusan payroll.
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_seconds')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->unsignedInteger('work_minutes')->default(0);

            // Hanya terisi dari overtime_records yang sudah confirmed. Menit
            // setelah jam pulang TANPA approval bukan lembur (BR-14).
            $table->unsignedInteger('overtime_minutes')->default(0);

            $table->foreignId('first_log_id')->nullable()->constrained('attendance_logs')->nullOnDelete();
            $table->foreignId('last_log_id')->nullable()->constrained('attendance_logs')->nullOnDelete();

            $table->boolean('has_adjustment')->default(false);
            $table->boolean('is_closed')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->string('source_note', 255)->nullable();
        });

        // Isi shift_key untuk baris lama sebelum unique baru dipasang.
        DB::statement('UPDATE attendances SET shift_key = COALESCE(shift_id, 0)');

        // Status lama `telat` dipindah ke dimensi lain: statusnya jadi `hadir`,
        // fakta telatnya tetap hidup di late_seconds/late_minutes. Tidak ada
        // informasi yang hilang, hanya berpindah tempat.
        DB::statement('UPDATE attendances SET late_minutes = CAST((late_seconds + 59) / 60 AS INTEGER)');
        DB::statement("UPDATE attendances SET status = 'hadir' WHERE status = 'telat'");

        // Baru sekarang tukar unique key.
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'work_date']);
            $table->unique(['employee_id', 'work_date', 'shift_key']);
            $table->index(['is_closed', 'work_date']);
        });

        // Dan paling akhir, buang kolom rupiah. Absensi mencatat fakta;
        // uang dihitung sekali saat payroll digenerate lalu dibekukan.
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['late_blocks', 'deduction_amount']);
        });

        // Koreksi absensi yang disetujui. APPEND-ONLY.
        //
        // Dikunci ke (employee_id, work_date, shift_key), BUKAN ke
        // attendance_id, karena attendances adalah tabel turunan yang boleh
        // dihapus dan dibangun ulang. Kalau koreksi menempel ke id barisnya,
        // recompute akan membuat keputusan manager jadi yatim.
        Schema::create('attendance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->integer('shift_key')->default(0);

            $table->unsignedBigInteger('request_id')->nullable();

            // set_check_in | set_check_out | set_status | waive_late
            // | waive_early_leave | revert
            $table->string('type', 24);

            $table->timestamp('value_time')->nullable();
            $table->string('value_status', 16)->nullable();

            $table->text('reason');
            $table->string('evidence_path', 255)->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Membatalkan koreksi = menambah baris, bukan menghapus baris.
            $table->foreignId('reverted_by_id')->nullable()->constrained('attendance_adjustments')->nullOnDelete();

            // Sengaja tanpa updated_at dan tanpa deleted_at.
            $table->timestamp('created_at')->nullable();

            $table->index(['employee_id', 'work_date', 'shift_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_adjustments');

        Schema::table('attendances', function (Blueprint $table) {
            $table->unsignedInteger('late_blocks')->default(0);
            $table->unsignedBigInteger('deduction_amount')->default(0);

            $table->dropUnique(['employee_id', 'work_date', 'shift_key']);
            $table->unique(['employee_id', 'work_date']);

            $table->dropConstrainedForeignId('roster_assignment_id');
            $table->dropConstrainedForeignId('division_id');
            $table->dropConstrainedForeignId('first_log_id');
            $table->dropConstrainedForeignId('last_log_id');

            $table->dropColumn([
                'shift_key', 'scheduled_out', 'late_minutes',
                'early_leave_seconds', 'early_leave_minutes', 'work_minutes',
                'overtime_minutes', 'has_adjustment', 'is_closed', 'closed_at',
                'source_note',
            ]);
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
            $table->dropConstrainedForeignId('import_batch_id');
            $table->dropColumn('resolved_at');
        });

        Schema::dropIfExists('import_batches');
    }
};
