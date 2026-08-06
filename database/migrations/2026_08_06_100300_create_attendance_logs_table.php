<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu baris = satu scan. Ini bentuk ternormalisasi dari device_callbacks
     * dan dari hasil tarikan get_attlog, jadi dua jalur menulis ke tabel yang
     * sama dan saling menambal.
     */
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();

            $table->string('cloud_id', 64);
            $table->string('pin', 32);

            // Waktu scan apa adanya dari sumber, zona Asia/Jakarta.
            // Webhook mengirim presisi menit, get_attlog presisi detik.
            $table->timestamp('scanned_at');

            // Salinan scanned_at yang detiknya dipangkas ke nol.
            //
            // PENTING: kolom ini yang jadi kunci anti-duplikat, bukan
            // scanned_at. Alasannya webhook mengirim "2020-07-21 10:11" dan
            // get_attlog mengirim "2020-07-21 10:11:29" untuk scan yang SAMA.
            // Kalau unique-nya dipasang di scanned_at, kedua jalur akan lolos
            // sebagai dua baris berbeda dan rekonsiliasi cron malah bikin
            // duplikat. Dengan dipangkas ke menit, keduanya bentrok dan yang
            // kedua ditolak, persis yang diinginkan.
            $table->timestamp('scan_minute');

            // Kode mentah dari mesin. Disimpan apa adanya, tidak divalidasi
            // ketat, karena mesin non-Fingerspot bisa kirim kode di luar
            // kamus di config/fingerspot.php.
            $table->unsignedTinyInteger('verify_mode')->nullable();
            $table->unsignedTinyInteger('status_scan')->nullable();

            // Fingerspot tidak punya field io_mode. Arah masuk/keluar sudah
            // terwakili status_scan (0=masuk, 1=keluar). Kolom ini disediakan
            // sesuai permintaan dan dibiarkan null sampai ada mesin yang
            // benar-benar mengirimnya.
            $table->unsignedTinyInteger('io_mode')->nullable();

            // URL S3 foto wajah yang diambil mesin saat scan.
            //
            // Dikirim seri VIVO, VIDA, VEGA, dan DS/DT. Mesin di sini
            // Vivo W-2421M, jadi kolom ini akan terisi. Ditarik ke kolom
            // sendiri, bukan dibiarkan di dalam payload, karena ini bukti
            // paling kuat saat ada sengketa "itu bukan saya" dan bakal sering
            // ditampilkan di dashboard.
            //
            // Catatan: yang disimpan cuma tautannya. Foto sesungguhnya ada di
            // S3 milik Fingerspot dan bisa saja kedaluwarsa, jadi kalau nanti
            // dipakai buat bukti jangka panjang, fotonya perlu diunduh dan
            // disimpan sendiri.
            $table->string('photo_url', 2048)->nullable();

            // webhook | sync
            $table->string('source', 16);

            // Potongan payload asal buat baris ini, biar bisa dilacak tanpa
            // menggali arsip mentah.
            $table->json('payload')->nullable();

            // Jejak ke arsip mentah. Null untuk baris hasil cron get_attlog,
            // karena jalur itu tidak lewat callback.
            $table->foreignId('device_callback_id')->nullable()
                ->constrained('device_callbacks')->nullOnDelete();

            $table->timestamps();

            // Kunci anti-duplikat. Menjaga scan yang sama tidak masuk dua kali
            // walau datang dari webhook dan cron sekaligus.
            $table->unique(['cloud_id', 'pin', 'scan_minute'], 'attendance_logs_scan_unique');

            // Pola query utama: ambil semua scan satu PIN pada rentang tanggal
            // buat dijadikan satu baris attendances.
            $table->index(['pin', 'scanned_at']);
            $table->index('scanned_at');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
