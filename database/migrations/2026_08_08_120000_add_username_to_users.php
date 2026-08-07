<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Login pakai nama panggilan, bukan email.
 *
 * Alasannya praktis: sebagian besar karyawan kafe tidak punya email kerja, dan
 * yang punya email pribadi pun salah ketik saat mengetiknya di layar ponsel
 * sambil terburu-buru sebelum shift. "dian" jauh lebih mudah diingat dan
 * diketik daripada "dian.pratama@kafe.test".
 *
 * Email tetap disimpan — masih dipakai untuk notifikasi nanti — tapi tidak lagi
 * jadi kunci masuk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 32)->nullable()->after('id');
        });

        // Isi untuk akun yang sudah ada.
        //
        // Manajemen dapat "admin"; karyawan dapat nama depannya. Tabrakan nama
        // depan diselesaikan dengan menambah huruf pertama nama berikutnya —
        // "dian" lalu "dians" — bukan angka, karena angka di belakang nama
        // paling sering salah diingat.
        $terpakai = [];

        foreach (DB::table('users')->orderBy('id')->get() as $user) {
            $dasar = $user->employee_id === null
                ? 'admin'
                : Str::of($user->name)->lower()->explode(' ')->first();

            $dasar = preg_replace('/[^a-z0-9]/', '', Str::ascii($dasar)) ?: 'user';
            $username = $dasar;
            $sisa = Str::of($user->name)->lower()->explode(' ')->slice(1)->implode('');
            $i = 0;

            while (in_array($username, $terpakai, true)) {
                $username = $i < strlen($sisa)
                    ? $dasar . substr(preg_replace('/[^a-z0-9]/', '', $sisa), 0, $i + 1)
                    : $dasar . ($i + 1);
                $i++;
            }

            $terpakai[] = $username;

            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
