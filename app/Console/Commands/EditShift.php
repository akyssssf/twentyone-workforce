<?php

namespace App\Console\Commands;

use App\Models\RosterAssignment;
use App\Models\Shift;
use Illuminate\Console\Command;

/**
 * Ubah jam master sebuah shift.
 *
 * Jam master berlaku untuk SEMUA tanggal, bukan cuma ke depan. Cron
 * menghitung ulang dua hari terakhir tiap 15 menit, dan hitung ulang manual
 * untuk rentang mana pun memakai jam yang sekarang tersimpan — jadi begitu jam
 * ini diubah, telat dan pulang cepat di tanggal lama pun ikut berubah setiap
 * kali dihitung lagi. Itu bukan efek samping yang bisa dihindari; itu sifat
 * jam master. Kalau yang dibutuhkan cuma satu tanggal, pakai roster:jam-khusus.
 *
 * Perubahan jam wajib dikonfirmasi, dan sebelum-sesudahnya ditampilkan supaya
 * salah ketik ketahuan sebelum menyentuh apa pun.
 */
class EditShift extends Command
{
    protected $signature = 'shift:edit
                            {kode : Kode shift: pagi, malam, middle}
                            {--mulai= : Jam masuk baru, mis. 12:00}
                            {--selesai= : Jam pulang baru, mis. 01:00}
                            {--tampilkan-jam : Tampilkan jamnya di layar karyawan}
                            {--sembunyikan-jam : Sembunyikan jamnya dari layar karyawan}
                            {--ya : Lewati konfirmasi}';

    protected $description = 'Ubah jam master atau tampilan jam sebuah shift';

    public function handle(): int
    {
        $shift = Shift::where('code', strtolower(trim((string) $this->argument('kode'))))->first();

        if ($shift === null) {
            $this->error("Shift dengan kode '{$this->argument('kode')}' tidak ada.");
            $this->line('Yang tersedia: '.Shift::pluck('code')->implode(', '));

            return self::FAILURE;
        }

        $perubahan = [];

        foreach (['mulai' => 'start_time', 'selesai' => 'end_time'] as $opsi => $kolom) {
            if (! $jam = $this->option($opsi)) {
                continue;
            }

            $bersih = $this->jam((string) $jam);

            if ($bersih === null) {
                $this->error("Format --{$opsi} harus HH:MM, mis. 12:00 atau 01:00.");

                return self::FAILURE;
            }

            $perubahan[$kolom] = $bersih;
        }

        if ($this->option('tampilkan-jam') && $this->option('sembunyikan-jam')) {
            $this->error('Pilih salah satu: --tampilkan-jam atau --sembunyikan-jam.');

            return self::FAILURE;
        }

        if ($this->option('tampilkan-jam')) {
            $perubahan['show_hours'] = true;
        } elseif ($this->option('sembunyikan-jam')) {
            $perubahan['show_hours'] = false;
        }

        if ($perubahan === []) {
            $this->error('Tidak ada yang diubah. Isi --mulai, --selesai, --tampilkan-jam, atau --sembunyikan-jam.');

            return self::FAILURE;
        }

        $mulaiBaru = $perubahan['start_time'] ?? $shift->start_time;
        $selesaiBaru = $perubahan['end_time'] ?? $shift->end_time;

        // Jam pulang yang tidak lebih besar dari jam masuk berarti shift ini
        // melewati tengah malam. Kolomnya ikut disesuaikan supaya tidak ada dua
        // sumber kebenaran yang bisa saling bertentangan.
        if (isset($perubahan['start_time']) || isset($perubahan['end_time'])) {
            $perubahan['crosses_midnight'] = substr($selesaiBaru, 0, 5) <= substr($mulaiBaru, 0, 5);
        }

        $this->line("Shift   : {$shift->name} ({$shift->code})");
        $this->line(sprintf('Sebelum : %s–%s%s',
            substr((string) $shift->start_time, 0, 5),
            substr((string) $shift->end_time, 0, 5),
            $shift->show_hours ? '' : ' (jam disembunyikan dari karyawan)'));
        $this->line(sprintf('Sesudah : %s–%s%s',
            substr($mulaiBaru, 0, 5),
            substr($selesaiBaru, 0, 5),
            ($perubahan['show_hours'] ?? $shift->show_hours) ? '' : ' (jam disembunyikan dari karyawan)'));

        $ubahJam = isset($perubahan['start_time']) || isset($perubahan['end_time']);

        if ($ubahJam) {
            $terdampak = RosterAssignment::where('shift_id', $shift->id)->count();

            $this->newLine();
            $this->warn('Jam master berlaku untuk SEMUA tanggal, termasuk yang sudah lewat.');
            $this->line("  {$terdampak} baris roster memakai shift ini. Telat dan pulang cepat di tanggal");
            $this->line('  lama ikut berubah setiap kali dihitung ulang — cron sudah melakukannya untuk');
            $this->line('  dua hari terakhir tanpa diminta.');
            $this->newLine();

            if (! $this->option('ya') && ! $this->confirm('Lanjutkan?', false)) {
                $this->line('Dibatalkan, tidak ada yang diubah.');

                return self::SUCCESS;
            }
        }

        $shift->update($perubahan);

        $this->newLine();
        $this->info("{$shift->name} diperbarui.");

        if ($ubahJam) {
            $this->newLine();
            $this->line('Supaya riwayatnya konsisten, hitung ulang dari tanggal shift ini mulai dipakai:');
            $this->line('  <info>php artisan attendance:compute --from=YYYY-MM-DD --to='.now()->toDateString().'</info>');
        }

        return self::SUCCESS;
    }

    /** Terima "8:00", "08:00", atau "08:00:00" — kembalikan "HH:MM:SS". */
    protected function jam(string $masukan): ?string
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($masukan), $m)) {
            return null;
        }

        $jam = (int) $m[1];
        $menit = (int) $m[2];

        if ($jam > 23 || $menit > 59) {
            return null;
        }

        return sprintf('%02d:%02d:%02d', $jam, $menit, (int) ($m[3] ?? 0));
    }
}
