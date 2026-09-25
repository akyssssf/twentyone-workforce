<?php

use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bonus (lembur & bonus manual) dibayar TERPISAH dari gaji.
 *
 * Bukan cuma soal tampilan: uangnya memang diserahkan sendiri, jadi bonus
 * tidak boleh ikut take home pay — kalau ikut, angka yang tertulis di slip
 * bukan angka yang diterima orangnya, dan itu jenis selisih yang paling
 * sering jadi keributan.
 *
 * Disimpan sebagai setelan supaya bisa dikembalikan tanpa deploy kalau suatu
 * saat kafe menggabungkannya lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->unsignedBigInteger('total_bonus')->default(0)->after('total_statutory');
        });

        foreach (DB::table('branches')->pluck('id') as $branchId) {
            if (! DB::table('settings')->where('branch_id', $branchId)->where('key', 'payroll.bonus_terpisah')->exists()) {
                DB::table('settings')->insert([
                    'branch_id' => $branchId,
                    'group' => 'payroll',
                    'key' => 'payroll.bonus_terpisah',
                    'value' => json_encode(true),
                    'type' => 'bool',
                    'label' => 'Bonus (lembur, bonus manual) dibayar terpisah dari gaji',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Settings::flush($branchId);
        }
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('total_bonus');
        });

        DB::table('settings')->where('key', 'payroll.bonus_terpisah')->delete();

        foreach (DB::table('branches')->pluck('id') as $branchId) {
            Settings::flush($branchId);
        }
    }
};
