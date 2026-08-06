<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 6 — Payroll & Sistem.
 *
 * Semua nominal disimpan sebagai integer rupiah penuh. Bukan decimal, bukan
 * float: SQLite tidak menegakkan tipe kolom, jadi decimal di sini cuma janji
 * kosong, sementara float menghasilkan selisih satu rupiah yang mustahil
 * dijelaskan ke karyawan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Periode penggajian 21 s/d 20 (D-01). Bukan bulan kalender - semua
        // penguncian menempel ke periode ini.
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16);

            $table->date('start_date');
            $table->date('end_date');
            $table->date('pay_date');

            // open | generating | generated | approved | locked | reopened
            $table->string('status', 16)->default('open');

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();

            $table->timestamps();

            $table->unique(['branch_id', 'code']);
            $table->index('status');
        });

        // Lapisan antara periode dan slip.
        //
        // Generate ulang tidak menimpa hasil lama: ia membuat versi baru dan
        // menandai yang lama `superseded`. Tanpa ini, "generate ulang" berarti
        // kehilangan bukti percobaan sebelumnya.
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1);

            // running | completed | failed | superseded
            $table->string('status', 16)->default('running');

            // ID rule_set yang dipakai saat menghitung. Inilah yang membuat
            // pertanyaan "kenapa potongan bulan ini beda" bisa dijawab.
            $table->json('rule_snapshot')->nullable();

            $table->unsignedInteger('employee_count')->default(0);
            $table->unsignedBigInteger('total_take_home_pay')->default(0);

            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['payroll_period_id', 'version']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('code', 24)->unique();

            // Nama, divisi, nomor induk, PIN SAAT ITU. Slip yang sudah terbit
            // tidak boleh berubah isinya hanya karena karyawan pindah divisi
            // bulan depan.
            $table->json('employee_snapshot')->nullable();

            $table->unsignedBigInteger('total_earning')->default(0);
            $table->unsignedBigInteger('total_deduction')->default(0);
            $table->unsignedBigInteger('total_statutory')->default(0);
            $table->bigInteger('take_home_pay')->default(0);

            $table->unsignedSmallInteger('scheduled_days')->default(0);
            $table->unsignedSmallInteger('present_days')->default(0);
            $table->unsignedSmallInteger('absent_days')->default(0);
            $table->unsignedSmallInteger('leave_days')->default(0);
            $table->unsignedSmallInteger('late_count')->default(0);
            $table->unsignedSmallInteger('early_leave_count')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);

            // draft | published
            $table->string('status', 16)->default('draft');

            $table->timestamp('published_at')->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->timestamp('pdf_generated_at')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
            $table->index(['employee_id', 'status']);
        });

        Schema::create('payslip_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->nullable()->constrained()->nullOnDelete();

            // earning | deduction | statutory | info
            $table->string('category', 16);

            // Disalin, bukan dirujuk. Slip gaji adalah dokumen yang sudah
            // diterima karyawan; isinya tidak boleh ikut berubah kalau manager
            // mengganti nama komponen gaji tahun depan.
            $table->string('label', 100);

            $table->decimal('qty', 8, 2)->default(1);
            $table->bigInteger('rate')->default(0);

            // Boleh negatif: dipakai baris penyesuaian periode lalu.
            $table->bigInteger('amount')->default(0);

            // Telusur balik ke asal uangnya: potongan telat -> attendances,
            // lembur -> overtime_records, kasbon -> cicilan. Inilah yang
            // membuat slip bisa "diklik sampai sumbernya" saat karyawan protes.
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->json('rule_snapshot')->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['payslip_id', 'category', 'sort_order']);
            $table->index(['source_type', 'source_id']);
        });

        // Riwayat gaji, bukan satu kolom di employees. Naik gaji bulan ini
        // tidak boleh mengubah slip gaji bulan lalu.
        Schema::create('employee_salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount')->default(0);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
        });

        // Bonus dan potongan manual digabung: siklus hidupnya identik (dibuat
        // manager, wajib beralasan, terikat satu periode, berakhir sebagai
        // baris slip). UI tetap menampilkannya sebagai dua menu terpisah.
        Schema::create('manual_payroll_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();

            // bonus | deduction
            $table->string('entry_type', 16);

            $table->foreignId('deduction_type_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount')->default(0);

            // WAJIB. BR-23: bonus harus punya alasan.
            $table->text('reason');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['payroll_period_id', 'employee_id']);
        });

        Schema::create('cash_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->unsignedTinyInteger('installments_count')->default(1);
            $table->text('reason');

            // pending | approved | disbursed | paid_off | cancelled
            $table->string('status', 16)->default('pending');

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamps();
        });

        // Payroll menarik cicilan yang jatuh tempo secara otomatis. Kalau
        // manager harus mengetik ulang tiap bulan, suatu saat pasti terlewat.
        Schema::create('cash_advance_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_advance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->unsignedBigInteger('amount');

            // scheduled | deducted | skipped | written_off
            $table->string('status', 16)->default('scheduled');

            $table->foreignId('payslip_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['cash_advance_id', 'sequence']);
        });

        // Koreksi yang datang setelah periode terkunci TIDAK membuka kunci
        // apa pun. Selisihnya dibayar di periode berikutnya sebagai baris
        // tersendiri, dengan origin_period_id menunjuk periode asal masalah.
        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('origin_period_id')->constrained('payroll_periods')->cascadeOnDelete();
            $table->foreignId('applied_period_id')->nullable()->constrained('payroll_periods')->nullOnDelete();

            // Boleh negatif: penyesuaian bisa menambah atau mengurangi.
            $table->bigInteger('amount');

            $table->text('reason');
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['applied_period_id', 'employee_id']);
        });

        // --- Sistem ---

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // user | system.
            //
            // Wajib dibedakan: tanpa ini, log tenggelam oleh perubahan otomatis
            // cron compute tiap 15 menit, dan approval cuti yang penting jadi
            // tidak terlihat.
            $table->string('actor_type', 16)->default('user');

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // Disalin: akun bisa dihapus, jejak tidak boleh putus.
            $table->string('actor_name', 100)->nullable();

            $table->string('action', 64);
            $table->string('auditable_type', 100)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('context')->nullable();

            // Tanpa updated_at: baris audit tidak pernah diubah.
            $table->timestamp('created_at')->nullable();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['actor_type', 'created_at']);
            $table->index('action');
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();

            // database | mail | whatsapp
            $table->string('channel', 16)->default('database');

            $table->string('subject', 150)->nullable();
            $table->text('body_template');
            $table->json('variables')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('template_code', 64)->nullable();
            $table->string('title', 150);
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->string('link', 255)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
        });

        // Pola outbox: notifications = apa yang perlu disampaikan,
        // deliveries = upaya menyampaikannya per kanal. Saat WhatsApp
        // ditambahkan nanti, yang bertambah cuma driver - tidak ada tabel yang
        // perlu diubah.
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['status', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('payroll_adjustments');
        Schema::dropIfExists('cash_advance_installments');
        Schema::dropIfExists('cash_advances');
        Schema::dropIfExists('manual_payroll_entries');
        Schema::dropIfExists('employee_salaries');
        Schema::dropIfExists('payslip_items');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('payroll_periods');
    }
};
