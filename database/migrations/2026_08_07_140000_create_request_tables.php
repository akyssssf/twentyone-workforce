<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 5 — Modul pengajuan.
 *
 * Pola induk + detail: `requests` memegang semua yang sama untuk keempat jenis
 * (kode, status, siapa mengajukan, siapa memutuskan), empat tabel detail
 * memegang yang khas per jenis dengan request_id sebagai PK sekaligus FK.
 *
 * Alternatif satu-tabel-dengan-kolom-JSON ditolak: partner_employee_id di dalam
 * JSON tidak bisa punya foreign key, jadi pengajuan tukar shift yang menunjuk
 * karyawan yang sudah dihapus akan lolos tanpa ada yang mencegah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requests', function (Blueprint $table) {
            $table->id();

            // Yang ditampilkan ke pengguna. Bukan id mentah, supaya karyawan
            // tidak bisa menebak /pengajuan/124 untuk mengintip milik orang
            // lain (otorisasi per baris tetap wajib, ini lapis kedua).
            $table->string('code', 24)->unique();

            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // leave | overtime | swap | correction
            $table->string('type', 16);

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // draft | pending_peer | pending_manager | approved | rejected
            // | cancelled | expired
            $table->string('status', 24)->default('draft');

            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            // Pengajuan yang menggantung tidak boleh mengubah roster di menit
            // terakhir. Lewat tanggal ini, status jadi `expired` otomatis.
            $table->timestamp('expires_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'type']);
            $table->index(['employee_id', 'status']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->foreignId('request_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('total_days', 4, 1)->default(1);
            $table->boolean('is_half_day')->default(false);
            $table->text('reason');
            $table->text('handover_note')->nullable();
        });

        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->foreignId('request_id')->primary()->constrained()->cascadeOnDelete();

            // Manager membuat lembur untuk 3 chef sekaligus = 3 baris request
            // (tiap orang punya realisasi & approval sendiri) yang dikelompokkan
            // UI lewat batch_id ini.
            $table->uuid('batch_id')->nullable();

            $table->date('work_date');
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->time('planned_start');
            $table->time('planned_end');
            $table->unsignedInteger('planned_minutes')->default(0);

            // manager | employee. Brief menyebut dua alur berbeda di dua
            // bagian; keduanya didukung, approval manager tetap wajib.
            $table->string('initiated_by', 16)->default('manager');

            // Approval susulan untuk kejadian darurat. BR-14 tetap berlaku
            // (tanpa approval bukan lembur), hanya saja approval boleh
            // diberikan setelahnya - dan setiap kali itu terjadi, tercatat.
            $table->boolean('is_backdated')->default(false);

            $table->text('reason');

            $table->index('batch_id');
        });

        Schema::create('shift_swap_requests', function (Blueprint $table) {
            $table->foreignId('request_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('requester_assignment_id')->constrained('roster_assignments')->cascadeOnDelete();
            $table->foreignId('partner_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('partner_assignment_id')->nullable()->constrained('roster_assignments')->nullOnDelete();
            $table->timestamp('partner_accepted_at')->nullable();
            $table->timestamp('partner_rejected_at')->nullable();
            $table->text('partner_note')->nullable();
            $table->text('reason');
        });

        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->foreignId('request_id')->primary()->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->integer('shift_key')->default(0);

            // lupa_masuk | lupa_pulang | mesin_error | lainnya
            $table->string('correction_type', 24);

            $table->timestamp('proposed_check_in')->nullable();
            $table->timestamp('proposed_check_out')->nullable();
            $table->string('proposed_status', 16)->nullable();
            $table->text('reason');
        });

        Schema::create('request_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained()->cascadeOnDelete();

            // Disk `local`, bukan `public`. Bukti sakit adalah data medis dan
            // tidak boleh bisa dibuka lewat URL tebakan.
            $table->string('path', 255);

            $table->string('original_name', 255);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');

            $table->decimal('entitlement_days', 5, 1)->default(0);
            $table->decimal('carried_over_days', 5, 1)->default(0);
            $table->decimal('used_days', 5, 1)->default(0);

            // Terpisah dari used_days: pengajuan yang belum diputuskan sudah
            // harus mengurangi saldo yang terlihat, kalau tidak karyawan bisa
            // mengajukan 12 hari cuti tiga kali sebelum satu pun diputuskan.
            $table->decimal('pending_days', 5, 1)->default(0);

            $table->date('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'year']);
        });

        // Setiap perubahan saldo cuti bisa dijelaskan. Pertanyaan "kok sisa
        // cuti saya berkurang 2 hari?" harus terjawab dari data.
        Schema::create('leave_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_balance_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('request_id')->nullable();
            $table->decimal('delta_days', 5, 1);

            // accrual | usage | reversal | carry_over | expiry | adjustment
            $table->string('type', 16);

            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['leave_balance_id', 'created_at']);
        });

        // Realisasi lembur, terpisah dari rencananya.
        //
        // Tanpa pemisahan ini, lembur yang disetujui 3 jam tapi orangnya pulang
        // setelah 1 jam akan tetap dibayar 3 jam.
        Schema::create('overtime_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->unsignedBigInteger('overtime_request_id')->nullable();
            $table->foreignId('attendance_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('actual_start')->nullable();
            $table->timestamp('actual_end')->nullable();
            $table->unsignedInteger('actual_minutes')->default(0);
            $table->unsignedInteger('approved_minutes')->default(0);

            // min(approved, actual) secara bawaan. Manager boleh menaikkan
            // dengan alasan tertulis.
            $table->unsignedInteger('payable_minutes')->default(0);

            // pending_confirmation | confirmed | rejected
            $table->string('status', 24)->default('pending_confirmation');

            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date', 'overtime_request_id'], 'overtime_records_unique');
            $table->index(['status', 'work_date']);
        });

        // FK yang sengaja ditunda: saat tabel-tabel ini dibuat di Batch 3 & 4,
        // `requests` belum ada.
        //
        // SQLite tidak bisa menambah FOREIGN KEY ke tabel yang sudah jadi -
        // itu keterbatasan engine, bukan Laravel. Jadi di SQLite relasi ini
        // ditegakkan service layer saja, dan begitu pindah ke MySQL/PostgreSQL
        // constraint-nya langsung ikut terpasang tanpa mengubah kode.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('roster_assignments', function (Blueprint $table) {
                $table->foreign('source_request_id')->references('id')->on('requests')->nullOnDelete();
            });

            Schema::table('attendance_adjustments', function (Blueprint $table) {
                $table->foreign('request_id')->references('id')->on('requests')->nullOnDelete();
            });

            Schema::table('leave_ledger', function (Blueprint $table) {
                $table->foreign('request_id')->references('id')->on('requests')->nullOnDelete();
            });

            Schema::table('overtime_records', function (Blueprint $table) {
                $table->foreign('overtime_request_id')->references('request_id')->on('overtime_requests')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_records');
        Schema::dropIfExists('leave_ledger');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('request_attachments');
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('shift_swap_requests');
        Schema::dropIfExists('overtime_requests');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('requests');
    }
};
