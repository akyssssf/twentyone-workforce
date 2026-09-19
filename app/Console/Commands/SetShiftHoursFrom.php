<?php

namespace App\Console\Commands;

use App\Models\Shift;
use App\Models\ShiftTimeOverride;
use App\Support\DateInput;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Ubah jam sebuah shift MULAI tanggal tertentu, tanpa menyentuh tanggal lama.
 *
 * Ini jawaban untuk "mulai besok Shift 2 tutup 23:30". shift:edit mengubah jam
 * master yang berlaku ke semua tanggal — termasuk yang sudah lewat begitu
 * dihitung ulang — jadi tidak bisa dipakai untuk perubahan yang punya tanggal
 * mulai. roster:jam-khusus menempel per-orang per-tanggal, jadi rapuh terhadap
 * urutan dan tidak cocok untuk perubahan permanen.
 *
 * Perubahan sebelumnya yang masih terbuka ditutup otomatis sehari sebelum
 * tanggal mulai yang baru: riwayatnya tetap ada, dan tidak ada dua periode
 * yang tumpang tindih.
 */
class SetShiftHoursFrom extends Command
{
    protected $signature = 'shift:jam
                            {kode : Kode shift: pagi, malam, middle}
                            {--dari= : Berlaku mulai tanggal (YYYY-MM-DD), wajib}
                            {--sampai= : Berlaku sampai tanggal, kosong berarti sampai ada perubahan berikutnya}
                            {--mulai= : Jam masuk, mis. 14:00}
                            {--selesai= : Jam pulang, mis. 23:30}
                            {--catatan= : Keterangan, mis. "jam operasional baru"}
                            {--hapus : Hapus periode yang mulai di tanggal --dari}';

    protected $description = 'Ubah jam shift mulai tanggal tertentu, tanpa menyentuh tanggal lama';

    public function handle(): int
    {
        $shift = Shift::with('timeOverrides')
            ->where('code', strtolower(trim((string) $this->argument('kode'))))
            ->first();

        if ($shift === null) {
            $this->error("Shift dengan kode '{$this->argument('kode')}' tidak ada.");
            $this->line('Yang tersedia: '.Shift::pluck('code')->implode(', '));

            return self::FAILURE;
        }

        if (! $this->option('dari')) {
            $this->error('Isi --dari: tanggal mulai berlakunya. Tanpa tanggal, pakai shift:edit (jam master).');

            return self::FAILURE;
        }

        $dari = DateInput::parseOrFail((string) $this->option('dari'), 'dari');
        $sampai = $this->option('sampai') ? DateInput::parseOrFail((string) $this->option('sampai'), 'sampai') : null;

        if ($sampai !== null && $sampai->lessThan($dari)) {
            $this->error('--sampai lebih awal dari --dari.');

            return self::FAILURE;
        }

        if ($this->option('hapus')) {
            return $this->hapus($shift, $dari);
        }

        $mulai = $this->jam((string) $this->option('mulai'));
        $selesai = $this->jam((string) $this->option('selesai'));

        if ($mulai === null || $selesai === null) {
            $this->error('Isi --mulai dan --selesai dalam format HH:MM, mis. 14:00 dan 23:30.');

            return self::FAILURE;
        }

        $sebelumnya = $shift->jamPada($dari->copy()->subDay());
        $saatIni = $shift->jamPada($dari);

        $this->line("Shift : {$shift->name}");
        $this->line(sprintf('  %s dan sebelumnya : %s', $dari->copy()->subDay()->translatedFormat('d M Y'), $this->format($sebelumnya)));
        $this->line(sprintf('  %s dan sesudahnya : %s  ->  %s–%s',
            $dari->translatedFormat('d M Y'),
            $this->format($saatIni),
            substr($mulai, 0, 5), substr($selesai, 0, 5)));

        // Periode yang tumpang tindih dengan yang baru.
        $bentrok = $shift->timeOverrides->filter(function (ShiftTimeOverride $o) use ($dari, $sampai) {
            $akhirBaru = $sampai;
            $akhirLama = $o->effective_to;

            $mulaiSebelumAkhirLama = $akhirLama === null || $dari->lessThanOrEqualTo($akhirLama);
            $mulaiLamaSebelumAkhirBaru = $akhirBaru === null || $o->effective_from->lessThanOrEqualTo($akhirBaru);

            return $mulaiSebelumAkhirLama && $mulaiLamaSebelumAkhirBaru;
        });

        foreach ($bentrok as $o) {
            // Periode terbuka yang mulai sebelum tanggal baru: ditutup sehari
            // sebelumnya. Itu memang arti "mulai tanggal X jamnya jadi begini".
            if ($o->effective_to === null && $o->effective_from->lessThan($dari)) {
                $o->update(['effective_to' => $dari->copy()->subDay()]);
                $this->line(sprintf('  periode lama (%s–%s, mulai %s) ditutup pada %s',
                    substr($o->start_time, 0, 5), substr($o->end_time, 0, 5),
                    $o->effective_from->translatedFormat('d M Y'),
                    $dari->copy()->subDay()->translatedFormat('d M Y')));

                continue;
            }

            // Selain itu tumpang tindih sungguhan — jangan ditebak mana yang
            // menang, tolak dan minta dibereskan.
            $this->error(sprintf('Bentrok dengan periode %s s/d %s (%s–%s). Hapus dulu dengan --hapus --dari=%s.',
                $o->effective_from->toDateString(),
                $o->effective_to?->toDateString() ?? 'seterusnya',
                substr($o->start_time, 0, 5), substr($o->end_time, 0, 5),
                $o->effective_from->toDateString()));

            return self::FAILURE;
        }

        ShiftTimeOverride::create([
            'shift_id' => $shift->id,
            'effective_from' => $dari,
            'effective_to' => $sampai,
            'start_time' => $mulai,
            'end_time' => $selesai,
            'note' => $this->option('catatan') ?: null,
        ]);

        $this->newLine();
        $this->info(sprintf('%s: %s–%s berlaku mulai %s%s.',
            $shift->name, substr($mulai, 0, 5), substr($selesai, 0, 5),
            $dari->translatedFormat('d M Y'),
            $sampai ? ' sampai '.$sampai->translatedFormat('d M Y') : ' dan seterusnya'));

        if ($dari->lessThanOrEqualTo(Carbon::today())) {
            $this->newLine();
            $this->warn('Tanggal mulainya sudah lewat atau hari ini: rekap tanggal itu ke atas berubah saat dihitung ulang.');
            $this->line('  <info>php artisan attendance:compute --from='.$dari->toDateString().' --to='.Carbon::today()->toDateString().'</info>');
        }

        return self::SUCCESS;
    }

    protected function hapus(Shift $shift, Carbon $dari): int
    {
        $o = $shift->timeOverrides->first(fn (ShiftTimeOverride $o) => $o->effective_from->isSameDay($dari));

        if ($o === null) {
            $this->error("Tidak ada periode jam {$shift->name} yang mulai pada {$dari->toDateString()}.");

            return self::FAILURE;
        }

        $o->delete();
        $this->info(sprintf('Periode %s–%s mulai %s dihapus. Tanggal itu kembali ke jam master atau periode sebelumnya.',
            substr($o->start_time, 0, 5), substr($o->end_time, 0, 5), $dari->translatedFormat('d M Y')));

        return self::SUCCESS;
    }

    /** @param  array{start_time: string, end_time: string, override: ?ShiftTimeOverride}  $jam */
    protected function format(array $jam): string
    {
        return substr($jam['start_time'], 0, 5).'–'.substr($jam['end_time'], 0, 5)
            .($jam['override'] ? '' : ' (master)');
    }

    protected function jam(string $masukan): ?string
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($masukan), $m)) {
            return null;
        }

        if ((int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
    }
}
