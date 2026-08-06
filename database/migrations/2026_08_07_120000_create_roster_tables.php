<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 3 — Roster.
 *
 * Setelah batch ini, roster_assignments jadi SATU-SATUNYA sumber kebenaran
 * untuk pertanyaan "hari ini dia shift apa / libur tidak".
 */
return new class extends Migration
{
    public function up(): void
    {
        // Kebutuhan tenaga per shift per divisi. BR-11 & BR-12 jadi DATA,
        // bukan angka yang ditanam di kode, supaya manager bisa menaikkan
        // kebutuhan waiter di akhir pekan tanpa minta tolong programmer.
        Schema::create('staffing_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('division_id')->constrained()->cascadeOnDelete();

            // all | weekday | weekend | holiday
            $table->string('day_type', 16)->default('all');

            $table->unsignedTinyInteger('required_count')->default(0);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['shift_id', 'division_id', 'day_type']);
        });

        // Header roster bulanan. Ada terpisah dari assignment supaya status
        // draft/published punya tempat: karyawan tidak boleh melihat jadwal
        // setengah jadi.
        Schema::create('rosters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');

            // draft | published | locked
            $table->string('status', 16)->default('draft');

            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'period_year', 'period_month']);
            $table->index('status');
        });

        Schema::create('roster_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roster_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');

            // Null = tidak bertugas (libur, cuti, tanggal merah).
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();

            // shift_id ?? 0.
            //
            // Ada karena D-04 mengizinkan double shift, sehingga
            // (employee_id, work_date) tidak lagi cukup jadi kunci. Dan karena
            // SEMUA engine memperlakukan NULL sebagai selalu-berbeda di unique
            // index, tanpa kolom ini satu karyawan bisa punya lima baris libur
            // di tanggal yang sama tanpa ada yang mencegah.
            //
            // Diisi model lewat trait HasShiftKey, bukan generated column,
            // karena SQLite tidak bisa ADD COLUMN bertipe STORED ke tabel yang
            // sudah berisi data - situasi yang persis kita hadapi di
            // attendances.
            $table->integer('shift_key')->default(0);

            // Bertugas sebagai apa hari itu. Disalin, bukan diambil dari
            // employee_divisions, karena waiter yang ditugaskan jadi kasir
            // harus terhitung mengisi kuota kasir.
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();

            // scheduled | off | leave | holiday | cancelled
            $table->string('status', 16)->default('scheduled');

            // manual | generated | swap | leave | correction
            $table->string('source', 16)->default('manual');

            $table->unsignedBigInteger('source_request_id')->nullable();

            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date', 'shift_key']);

            // Pola query utama: hitung pemenuhan kebutuhan satu shift.
            $table->index(['work_date', 'shift_id', 'division_id']);
            $table->index(['employee_id', 'work_date']);
            $table->index(['roster_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_assignments');
        Schema::dropIfExists('rosters');
        Schema::dropIfExists('staffing_requirements');
    }
};
