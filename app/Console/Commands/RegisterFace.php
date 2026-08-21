<?php

namespace App\Console\Commands;

use App\Models\DeviceCallback;
use App\Models\Employee;
use App\Services\Fingerspot\FaceTemplate;
use App\Services\Fingerspot\FingerspotClient;
use App\Services\Fingerspot\FingerspotException;
use Illuminate\Console\Command;

/**
 * Daftarkan wajah karyawan ke mesin dari jarak jauh.
 *
 * Menghapus satu-satunya bagian pendaftaran karyawan yang dulu mengharuskan
 * seseorang berdiri di depan mesin: cukup minta swafoto, sisanya dari mana saja.
 *
 * Yang membedakan perintah ini dari menyusun request-nya sendiri adalah bagian
 * paling gampang dilewat: `set_userinfo` ASINKRON, dan respons `success: true`
 * cuma berarti perintahnya diterima. Berhasil-tidaknya baru datang belakangan
 * ke webhook mesin. Perintah ini menunggu callback itu dan melaporkan hasil yang
 * sebenarnya — kalau tidak, "sudah didaftarkan" berarti "sudah dikirim", dan
 * bedanya baru ketahuan waktu orangnya berdiri di depan mesin dan tidak dikenali.
 */
class RegisterFace extends Command
{
    protected $signature = 'employee:daftar-wajah
                            {pin : PIN karyawan di mesin}
                            {foto : Berkas foto JPEG, maksimal 100 KB, close-up wajah}
                            {--privilege=1 : 1=pengguna, 2=admin, 3=subadmin}
                            {--tunggu=60 : Detik menunggu hasil dari mesin, 0 berarti tidak menunggu}';

    protected $description = 'Daftarkan wajah karyawan ke mesin Fingerspot dari jarak jauh';

    public function handle(FingerspotClient $client): int
    {
        $pin = (string) $this->argument('pin');
        $employee = Employee::where('pin_device', $pin)->first();

        if ($employee === null) {
            $this->error("Tidak ada karyawan dengan PIN {$pin}.");
            $this->line('Daftarkan orangnya dulu: <info>php artisan employee:add --pin='.$pin.' --name="..."</info>');

            return self::FAILURE;
        }

        try {
            $template = FaceTemplate::dariBerkas((string) $this->argument('foto'));
        } catch (FingerspotException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Karyawan : {$employee->name} (PIN {$pin})");
        $this->line('Foto     : '.$this->argument('foto').' — '.number_format(filesize((string) $this->argument('foto')) / 1024, 1).' KB');

        try {
            $transId = $client->setUserInfo($pin, [
                'name' => $employee->name,
                'privilege' => (string) $this->option('privilege'),
                'template' => $template,
            ]);
        } catch (FingerspotException $e) {
            $this->error('Gagal mengirim ke Fingerspot: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("trans_id : {$transId}");
        $this->newLine();
        $this->info('Perintah diterima Fingerspot.');

        $tunggu = (int) $this->option('tunggu');

        if ($tunggu <= 0) {
            $this->warn('Belum tentu terdaftar — hasilnya datang belakangan lewat webhook mesin.');
            $this->line('Jalankan ulang perintah ini tanpa --tunggu=0 untuk memastikan (aman diulang).');

            return self::SUCCESS;
        }

        return $this->tungguHasil($transId, $tunggu);
    }

    /**
     * Tunggu callback mesin untuk trans_id ini.
     *
     * Dicocokkan lewat trans_id saja, bukan lewat `type`: nilai type untuk
     * callback set_userinfo tidak pernah kami lihat langsung di lapangan, dan
     * mencocokkan sesuatu yang cuma ditebak akan membuat perintah ini melapor
     * "tidak ada jawaban" padahal jawabannya sudah datang. trans_id sudah unik.
     */
    protected function tungguHasil(string $transId, int $detik): int
    {
        $this->line("Menunggu jawaban mesin (maksimal {$detik} detik)...");

        $batas = time() + $detik;

        do {
            $callback = DeviceCallback::where('trans_id', $transId)->latest('id')->first();

            if ($callback !== null) {
                return $this->laporkan($callback);
            }

            sleep(2);
        } while (time() < $batas);

        $this->newLine();
        $this->warn('Mesin belum menjawab sampai batas waktu. BELUM tentu gagal, dan belum tentu berhasil.');
        $this->line("Callback-nya akan tetap tersimpan di device_callbacks dengan trans_id {$transId}");
        $this->line('kalau datang belakangan. Perintah ini aman diulang.');

        // Sengaja bukan SUCCESS: hasil yang tidak diketahui tidak boleh terbaca
        // sebagai berhasil oleh skrip mana pun yang memanggil perintah ini.
        return self::FAILURE;
    }

    protected function laporkan(DeviceCallback $callback): int
    {
        $status = (string) data_get($callback->payload, 'data.status', '');

        $this->newLine();

        if ($status === '1') {
            $this->info('Mesin mengonfirmasi: wajah terdaftar.');

            return self::SUCCESS;
        }

        if ($status === '2') {
            $this->error('Mesin menolak pendaftaran (status 2).');
            $this->line('Paling sering karena fotonya bukan close-up, terlalu gelap, atau wajahnya tidak terdeteksi.');

            return self::FAILURE;
        }

        $this->error("Mesin menjawab dengan status yang tidak dikenal: \"{$status}\".");
        $this->line('Isi callback-nya: '.json_encode($callback->payload));

        return self::FAILURE;
    }
}
