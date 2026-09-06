<?php

namespace App\Console\Commands;

use App\Models\DeviceCallback;
use App\Models\Employee;
use App\Services\Fingerspot\FingerspotClient;
use App\Services\Fingerspot\FingerspotException;
use Illuminate\Console\Command;

/**
 * Denyut mesin: apakah perangkatnya masih terhubung ke cloud SAAT INI.
 *
 * Memisahkan dua keadaan yang dari sisi aplikasi terlihat persis sama saat scan
 * berhenti masuk: mesin yang kehilangan koneksi, dan mesin yang terhubung tapi
 * memang tidak ada yang scan. Keduanya sama-sama sepi, dan menebaknya berarti
 * menyuruh orang datang ke kafe tengah malam untuk sesuatu yang mungkin bukan
 * masalah mesin.
 *
 * `fingerspot:check` TIDAK bisa menjawab ini — dia memanggil get_device, yang
 * cuma membaca catatan cloud tentang perangkat dan tetap berhasil walaupun
 * mesinnya sudah tercabut seminggu. Perintah ini memakai get_userinfo yang
 * asinkron: mesin sendiri yang harus mengeksekusi lalu menjawab lewat webhook,
 * jadi datangnya callback adalah bukti mesin hidup dan terhubung.
 *
 * Read-only. Tidak mengubah apa pun di mesin.
 */
class PingDevice extends Command
{
    protected $signature = 'fingerspot:ping
                            {--pin= : PIN yang ditanyakan, kosong berarti pakai karyawan pertama}
                            {--tunggu=60 : Detik menunggu jawaban mesin}';

    protected $description = 'Cek apakah mesin absensi masih terhubung, tanpa perlu ada orang di depannya';

    public function handle(FingerspotClient $client): int
    {
        $pin = (string) ($this->option('pin')
            ?: Employee::query()->tracked()->employed()->whereNotNull('pin_device')->value('pin_device'));

        if ($pin === '') {
            $this->error('Tidak ada PIN yang bisa dipakai. Sebutkan dengan --pin.');

            return self::FAILURE;
        }

        $this->line("Menanyai mesin soal PIN {$pin}...");

        try {
            $transId = $client->getUserInfo($pin);
        } catch (FingerspotException $e) {
            $this->error('Tidak bisa menghubungi API Fingerspot: '.$e->getMessage());
            $this->line('Ini kegagalan SERVER KE CLOUD, jadi belum bilang apa-apa soal mesinnya.');

            return self::FAILURE;
        }

        $this->line("trans_id : {$transId}");
        $this->info('Perintah diterima Fingerspot. Sekarang menunggu MESIN yang menjawab.');

        $detik = max(1, (int) $this->option('tunggu'));
        $batas = time() + $detik;

        do {
            if (DeviceCallback::where('trans_id', $transId)->exists()) {
                $this->newLine();
                $this->info('Mesin MENJAWAB — perangkatnya hidup dan terhubung ke cloud.');
                $this->line('Berarti scan yang tidak masuk bukan karena koneksi mesin.');
                $this->line('Periksa: apakah memang ada yang scan, dan apakah scannya berhasil');
                $this->line('(percobaan yang gagal tidak pernah dikirim ke API mana pun).');

                return self::SUCCESS;
            }

            sleep(2);
        } while (time() < $batas);

        $this->newLine();
        $this->error("Mesin TIDAK menjawab dalam {$detik} detik.");
        $this->line('Perintahnya diterima cloud Fingerspot, tapi perangkatnya tidak mengeksekusi —');
        $this->line('tanda kuat mesin sedang tidak terhubung ke internet, walaupun layarnya menyala');
        $this->line('dan masih bisa merekam scan secara lokal.');
        $this->newLine();
        $this->line('Yang perlu dicek di lokasi: kabel jaringan atau Wi-Fi mesin, dan router kafe.');

        // Tidak menjawab bukan "tidak tahu": untuk perintah asinkron, diam
        // sampai batas waktu memang bukti yang bisa dipakai.
        return self::FAILURE;
    }
}
