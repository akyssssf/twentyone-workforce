<?php

use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dua setelan baru yang menyentuh uang, dimasukkan sebagai baris setelan
 * (bukan konstanta) supaya bisa diubah dari menu Aturan tanpa deploy.
 *
 *   attendance.late_tolerance_minutes  toleransi telat; sampai batas ini
 *                                      tidak dihitung telat dan tidak dipotong
 *   payroll.hours_per_day              pembagi tarif per jam:
 *                                      gaji pokok ÷ hari kerja ÷ jam ini
 *
 * Nilai di sini adalah keputusan kafe (10 menit, 10 jam). Bawaan di kode
 * untuk toleransi tetap 0 — memaafkan telat diam-diam bukan bawaan yang aman.
 */
return new class extends Migration
{
    public function up(): void
    {
        $branchIds = DB::table('branches')->pluck('id');

        foreach ($branchIds as $branchId) {
            foreach ([
                ['attendance.late_tolerance_minutes', 10, 'Toleransi telat (menit), tidak dihitung telat sampai batas ini'],
                ['payroll.hours_per_day', 10, 'Jam kerja per hari, pembagi tarif per jam'],
            ] as [$key, $value, $label]) {
                $ada = DB::table('settings')->where('branch_id', $branchId)->where('key', $key)->exists();

                if ($ada) {
                    continue;
                }

                DB::table('settings')->insert([
                    'branch_id' => $branchId,
                    'group' => explode('.', $key)[0],
                    'key' => $key,
                    'value' => json_encode($value),
                    'type' => 'int',
                    'label' => $label,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Setelan dibaca lewat cache satu jam. Tanpa ini, toleransi yang
            // baru dipasang tidak terbaca sampai cache-nya kedaluwarsa — dan
            // payroll pertama setelah deploy tetap memotong telat 3 menit.
            Settings::flush($branchId);
        }
    }

    public function down(): void
    {
        DB::table('settings')
            ->whereIn('key', ['attendance.late_tolerance_minutes', 'payroll.hours_per_day'])
            ->delete();

        foreach (DB::table('branches')->pluck('id') as $branchId) {
            Settings::flush($branchId);
        }
    }
};
