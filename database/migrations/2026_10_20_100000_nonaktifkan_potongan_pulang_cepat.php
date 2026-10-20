<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kafe tidak memotong gaji untuk pulang cepat (keputusan pemilik, 20 Oktober 2026).
 *
 * Dinonaktifkan, bukan dihapus — sama seperti BPJS: kalau kebijakannya berubah,
 * cukup diaktifkan lagi. Pulang cepat tetap TERCATAT di rekap absensi sebagai
 * angka; yang hilang cuma potongan uangnya.
 *
 * Asal angkanya: scan pulang beberapa menit sebelum jam tutup (mis. 23:15 untuk
 * shift yang bubar 23:30) terbaca "pulang cepat 15 menit" dan jatuh ke tier
 * seeder yang tidak pernah dikonfirmasi pemilik.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('rule_sets')->where('type', 'early_leave')->update(['is_active' => false]);
    }

    public function down(): void
    {
        DB::table('rule_sets')->where('type', 'early_leave')->update(['is_active' => true]);
    }
};
