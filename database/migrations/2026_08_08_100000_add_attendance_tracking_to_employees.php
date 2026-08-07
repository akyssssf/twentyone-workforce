<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pisahkan "apakah orang ini diabsen" dari "apa status kepegawaiannya".
 *
 * Sebelumnya admin ditandai dengan employment_status = 'admin'. Itu mencampur
 * dua hal yang berbeda: employment_status menjawab *masih bekerja atau sudah
 * resign*, sedangkan yang dibutuhkan adalah *ikut diabsen atau tidak*.
 *
 * Akibat pencampuran itu ada dua, dan keduanya diam-diam:
 *
 *   1. Admin tetap ikut dihitung absen, karena penyaringnya memakai kolom
 *      is_active — bukan employment_status. Setiap hari mereka muncul sebagai
 *      Alpha, dan potongan alpha ikut terhitung di payroll.
 *   2. scopeEmployed() yang mencari employment_status = 'active' justru
 *      MENGELUARKAN admin dari daftar karyawan yang masih bekerja.
 *
 * Dengan kolom sendiri, keduanya jadi pertanyaan terpisah yang bisa dijawab
 * benar: admin tetap pegawai aktif, tapi tidak dijadwalkan dan tidak diabsen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Bawaannya true: mayoritas orang memang diabsen, dan default yang
            // salah arah lebih berbahaya di sini — karyawan yang tidak sengaja
            // luput dari absensi tidak menimbulkan error apa pun.
            $table->boolean('tracks_attendance')->default(true)->after('employment_status');

            // Foto profil yang disimpan sendiri.
            //
            // Foto dari mesin ada di S3 milik Fingerspot dan tautannya bisa
            // kedaluwarsa. Untuk foto yang dipakai sehari-hari di daftar
            // karyawan, salinan lokal lebih tepat; photo_url di attendance_logs
            // tetap dipertahankan apa adanya sebagai bukti per scan.
            $table->string('photo_path', 255)->nullable()->after('email');

            $table->index('tracks_attendance');
        });

        // Kembalikan employment_status ke maknanya semula, lalu pindahkan
        // informasi "admin" ke kolom yang tepat.
        DB::table('employees')
            ->where('employment_status', 'admin')
            ->update([
                'employment_status' => 'active',
                'tracks_attendance' => false,
            ]);

        // Bersihkan absensi & jadwal yang terlanjur dibuat untuk mereka.
        // Baris-baris ini murni turunan, jadi menghapusnya tidak menghilangkan
        // apa pun yang tidak bisa dibangun ulang.
        $tidakDiabsen = DB::table('employees')->where('tracks_attendance', false)->pluck('id');

        if ($tidakDiabsen->isNotEmpty()) {
            DB::table('attendances')->whereIn('employee_id', $tidakDiabsen)->delete();
            DB::table('roster_assignments')->whereIn('employee_id', $tidakDiabsen)->delete();
        }
    }

    public function down(): void
    {
        DB::table('employees')
            ->where('tracks_attendance', false)
            ->update(['employment_status' => 'admin']);

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['tracks_attendance']);
            $table->dropColumn(['tracks_attendance', 'photo_path']);
        });
    }
};
