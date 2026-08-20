<?php

namespace App\Console\Commands;

use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Support\DateInput;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Pasang jam khusus untuk satu shift pada satu tanggal.
 *
 * Jam di master shift berlaku global, jadi mengubahnya untuk sehari akan ikut
 * mengubah tanggal lain — termasuk yang sudah lewat, karena cron menghitung
 * ulang dua hari terakhir tiap 15 menit. Perintah ini menimpanya di tingkat
 * roster: nama shift, warna, dan hitungan kuota tenaga tetap seperti biasa,
 * yang berbeda cuma jamnya, di tanggal itu saja.
 */
class SetSpecialHours extends Command
{
    protected $signature = 'roster:jam-khusus
                            {tanggal : Tanggal (YYYY-MM-DD)}
                            {shift : Kode shift (pagi, malam, middle)}
                            {mulai : Jam mulai, mis. 08:00}
                            {selesai : Jam selesai, mis. 16:00}
                            {--hapus : Kembalikan ke jam master shift}
                            {--recompute : Hitung ulang absensi tanggal itu}';

    protected $description = 'Pasang jam khusus satu shift untuk satu tanggal saja';

    public function handle(): int
    {
        $tanggal = DateInput::parseOrFail((string) $this->argument('tanggal'), 'tanggal');
        $kode = (string) $this->argument('shift');

        $shift = Shift::where('code', $kode)->first();

        if ($shift === null) {
            $this->error("Shift dengan kode '{$kode}' tidak ada.");
            $this->line('Yang tersedia: '.Shift::pluck('code')->implode(', '));

            return self::FAILURE;
        }

        $mulai = $this->jam((string) $this->argument('mulai'));
        $selesai = $this->jam((string) $this->argument('selesai'));

        if ($mulai === null || $selesai === null) {
            $this->error('Format jam harus HH:MM, mis. 08:00 atau 13:30.');

            return self::FAILURE;
        }

        $baris = RosterAssignment::with('employee')
            ->whereDate('work_date', $tanggal)
            ->where('shift_id', $shift->id)
            ->get();

        if ($baris->isEmpty()) {
            $this->warn("Tidak ada yang dijadwalkan {$shift->name} pada {$tanggal->toDateString()}.");

            return self::SUCCESS;
        }

        $hapus = (bool) $this->option('hapus');

        foreach ($baris as $a) {
            $a->update([
                'start_time_override' => $hapus ? null : $mulai,
                'end_time_override' => $hapus ? null : $selesai,
            ]);
        }

        $this->info(sprintf('%s, %s: %s (%d orang)',
            $tanggal->translatedFormat('l, d F Y'),
            $shift->name,
            $hapus
                ? 'kembali ke jam master '.substr($shift->start_time, 0, 5).'–'.substr($shift->end_time, 0, 5)
                : 'jam khusus '.substr($mulai, 0, 5).'–'.substr($selesai, 0, 5),
            $baris->count()));

        foreach ($baris as $a) {
            $this->line('   '.$a->employee?->name);
        }

        if ($this->option('recompute')) {
            Artisan::call('attendance:compute', [
                '--from' => $tanggal->toDateString(),
                '--to' => $tanggal->toDateString(),
            ]);

            $this->newLine();
            $this->info('Absensi tanggal itu dihitung ulang.');
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
