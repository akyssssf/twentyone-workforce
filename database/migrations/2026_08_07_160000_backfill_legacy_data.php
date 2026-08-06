<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill data lama ke struktur baru.
 *
 * Sengaja dipisah dari migration yang membuat strukturnya: kalau backfill
 * gagal di tengah, struktur tetap utuh dan migration ini bisa diulang setelah
 * penyebabnya dibereskan.
 *
 * Kolom lama baru dihapus di paling akhir, setelah datanya benar-benar sudah
 * pindah - bukan di migration yang sama dengan pemindahannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Ambil SN mesin dari scan yang benar-benar pernah masuk, bukan dari
        // config. Config bisa saja belum diisi, sementara data scan tidak
        // berbohong soal mesin mana yang mengirimnya.
        $cloudId = DB::table('attendance_logs')->value('cloud_id')
            ?: (config('fingerspot.cloud_id') ?: 'default');

        // Cabang bawaan. Dibuat di sini, bukan di seeder, karena migration
        // berikutnya sudah membutuhkannya dan seeder tidak dijamin jalan.
        $branchId = DB::table('branches')->where('code', 'pusat')->value('id');

        if ($branchId === null) {
            $branchId = DB::table('branches')->insertGetId([
                'code' => 'pusat',
                'name' => config('app.name', 'Kafe'),
                'timezone' => config('attendance.timezone', 'Asia/Jakarta'),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('employees')->whereNull('branch_id')->update(['branch_id' => $branchId]);

        // Nomor induk internal untuk karyawan yang sudah ada. Kafe boleh
        // menimpanya belakangan dengan penomoran sendiri.
        $employees = DB::table('employees')->orderBy('id')->get();

        foreach ($employees as $index => $employee) {
            $updates = [];

            if (empty($employee->employee_no)) {
                $updates['employee_no'] = sprintf('EMP-%03d', $index + 1);
            }

            $updates['employment_status'] = $employee->is_active ? 'active' : 'resigned';

            DB::table('employees')->where('id', $employee->id)->update($updates);

            // PIN mesin -> pemetaan berperiode.
            //
            // valid_from mundur ke tanggal bergabung supaya seluruh riwayat
            // scan yang sudah ada tetap tercocokkan. Kalau tanggal bergabung
            // tidak diketahui, dipakai tanggal yang pasti lebih awal dari data
            // mana pun.
            $exists = DB::table('employee_devices')
                ->where('employee_id', $employee->id)
                ->whereNull('valid_to')
                ->exists();

            if (! $exists && ! empty($employee->pin_device)) {
                DB::table('employee_devices')->insert([
                    'employee_id' => $employee->id,
                    'cloud_id' => $cloudId,
                    'pin' => $employee->pin_device,

                    // Wajib tanggal murni. Kalau ikut membawa jam
                    // ("2026-08-06 00:00:00"), perbandingan string di SQL
                    // pencocokan di bawah akan selalu gagal karena
                    // "2026-08-06" secara leksikal LEBIH KECIL daripada
                    // "2026-08-06 00:00:00".
                    'valid_from' => substr((string) ($employee->joined_at ?: '2020-01-01'), 0, 10),
                    'valid_to' => null,
                    'note' => 'Dipindahkan otomatis dari employees.pin_device',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Gaji pokok -> riwayat gaji.
        $componentId = DB::table('salary_components')->where('code', 'gaji_pokok')->value('id');

        if ($componentId === null) {
            $componentId = DB::table('salary_components')->insertGetId([
                'code' => 'gaji_pokok',
                'name' => 'Gaji Pokok',
                'category' => 'earning',
                'calc_type' => 'fixed',
                'is_taxable' => true,
                'sort_order' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (Schema::hasColumn('employees', 'base_salary')) {
            foreach ($employees as $employee) {
                $sudahAda = DB::table('employee_salaries')
                    ->where('employee_id', $employee->id)
                    ->where('salary_component_id', $componentId)
                    ->exists();

                if ($sudahAda) {
                    continue;
                }

                DB::table('employee_salaries')->insert([
                    'employee_id' => $employee->id,
                    'salary_component_id' => $componentId,
                    'amount' => (int) ($employee->base_salary ?? 0),
                    'effective_from' => $employee->joined_at ?: '2020-01-01',
                    'effective_to' => null,
                    'note' => 'Dipindahkan otomatis dari employees.base_salary',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Cocokkan scan lama ke karyawan lewat pemetaan yang baru dibuat.
        DB::statement(<<<'SQL'
            UPDATE attendance_logs
            SET employee_id = (
                SELECT ed.employee_id
                FROM employee_devices ed
                WHERE ed.pin = attendance_logs.pin
                  AND ed.deleted_at IS NULL
                  AND date(attendance_logs.scanned_at) >= date(ed.valid_from)
                  AND (ed.valid_to IS NULL OR date(attendance_logs.scanned_at) <= date(ed.valid_to))
                LIMIT 1
            )
            WHERE employee_id IS NULL
        SQL);

        DB::table('attendance_logs')->whereNotNull('employee_id')->update(['resolved_at' => $now]);

        // Sekarang baru buang yang lama.
        //
        // pin_device dipertahankan sebagai cermin baca-saja (PIN aktif) supaya
        // command CLI dan tampilan yang sudah ada tidak perlu diubah semua
        // sekaligus. Tapi unique-nya dilepas: kalau karyawan resign dan PIN-nya
        // dipakai orang baru, dua baris akan memegang PIN yang sama dan itu sah.
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['pin_device']);
            $table->index('pin_device');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('base_salary');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedBigInteger('base_salary')->default(0);
        });

        DB::statement(<<<'SQL'
            UPDATE employees
            SET base_salary = COALESCE((
                SELECT es.amount FROM employee_salaries es
                JOIN salary_components sc ON sc.id = es.salary_component_id
                WHERE es.employee_id = employees.id AND sc.code = 'gaji_pokok'
                ORDER BY es.effective_from DESC LIMIT 1
            ), 0)
        SQL);

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['pin_device']);
            $table->unique('pin_device');
        });
    }
};
