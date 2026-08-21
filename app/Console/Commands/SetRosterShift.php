<?php

namespace App\Console\Commands;

use App\Models\Division;
use App\Models\Employee;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Services\Roster\RosterService;
use App\Support\DateInput;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Ubah jadwal satu orang pada satu tanggal, atau beberapa tanggal sekaligus.
 *
 * Ada karena perubahan jadwal harian ("si A besok tukar ke malam", "si B
 * Jumat libur") jauh lebih sering terjadi daripada penyusunan roster sebulan
 * penuh — dan sebelum ini satu-satunya jalan di server adalah menempel skrip
 * PHP panjang lewat SSH, yang gampang kelewat separuh.
 *
 * Perubahannya lewat RosterService::assign(), jadi ikut semua penjagaan yang
 * sudah ada di sana: shift lama dipindah (bukan ditambah jadi dobel), dan
 * cuti yang sudah disetujui tidak bisa ketiban jadwal kerja.
 */
class SetRosterShift extends Command
{
    protected $signature = 'roster:set
                            {pin : PIN karyawan di mesin}
                            {jadwal* : Pasangan tanggal=shift, mis. 2026-08-21=malam 2026-08-23=libur. Tanggal boleh rentang: 2026-09-01..2026-09-30=pagi}
                            {--divisi= : Kode divisi (kasir, waiter, chef, barista, logistik). Kosong = ikut divisi utamanya}
                            {--recompute : Hitung ulang absensi tanggal-tanggal itu}';

    protected $description = 'Ubah jadwal shift seseorang pada satu atau beberapa tanggal';

    /** Batas jumlah hari untuk satu rentang, penjaga terhadap salah ketik tahun. */
    protected const MAX_HARI = 62;

    public function handle(RosterService $service): int
    {
        $employee = Employee::where('pin_device', (string) $this->argument('pin'))->first();

        if ($employee === null) {
            $this->error("Tidak ada karyawan dengan PIN {$this->argument('pin')}.");

            return self::FAILURE;
        }

        $divisi = null;

        if ($kode = $this->option('divisi')) {
            $divisi = Division::where('code', $kode)->first();

            if ($divisi === null) {
                $this->error("Divisi dengan kode '{$kode}' tidak ada.");
                $this->line('Yang tersedia: '.Division::pluck('code')->implode(', '));

                return self::FAILURE;
            }
        }

        $rencana = [];

        foreach ($this->argument('jadwal') as $pasangan) {
            if (! str_contains($pasangan, '=')) {
                $this->error("Format salah: '{$pasangan}'. Pakai tanggal=shift, mis. 2026-08-21=malam");

                return self::FAILURE;
            }

            [$tgl, $kodeShift] = explode('=', $pasangan, 2);

            $kodeShift = strtolower(trim($kodeShift));

            // "libur"/"off"/"-" berarti tidak ada shift, bukan shift bernama itu.
            $shift = in_array($kodeShift, ['libur', 'off', '-'], true)
                ? null
                : Shift::where('code', $kodeShift)->first();

            if ($shift === null && ! in_array($kodeShift, ['libur', 'off', '-'], true)) {
                $this->error("Shift dengan kode '{$kodeShift}' tidak ada.");
                $this->line('Yang tersedia: '.Shift::pluck('code')->implode(', ').', atau libur');

                return self::FAILURE;
            }

            try {
                $tanggalnya = $this->tanggalDari(trim($tgl));
            } catch (\InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            foreach ($tanggalnya as $tanggal) {
                $rencana[] = [$tanggal, $shift];
            }
        }

        $this->line("Karyawan: {$employee->name} (PIN {$employee->pin_device})");
        $this->newLine();

        $gagal = 0;

        foreach ($rencana as [$tanggal, $shift]) {
            $sebelum = $this->jadwalSaatIni($employee, $tanggal);

            try {
                $service->assign(
                    $service->findOrCreate((int) $tanggal->year, (int) $tanggal->month),
                    $employee,
                    $tanggal,
                    $shift?->id,
                    $divisi?->id,
                );

                $this->line(sprintf('  %s  %-16s -> %s',
                    $tanggal->format('D d M'),
                    $sebelum,
                    $shift?->name ?? 'LIBUR'));
            } catch (RuntimeException $e) {
                $this->error(sprintf('  %s  GAGAL: %s', $tanggal->format('D d M'), $e->getMessage()));
                $gagal++;
            }
        }

        if ($this->option('recompute')) {
            $this->newLine();
            $this->info('Menghitung ulang absensi...');

            // Sekali jalan dari tanggal paling awal sampai paling akhir, bukan
            // satu panggilan per tanggal: sebulan penuh berarti 30 panggilan
            // yang masing-masing menyapu seluruh karyawan. Tanggal di antaranya
            // yang tidak diubah ikut terhitung ulang, dan itu tidak apa-apa —
            // attendances memang tabel turunan yang boleh dibangun ulang.
            $semua = array_map(fn (array $baris) => $baris[0], $rencana);

            Artisan::call('attendance:compute', [
                '--from' => min($semua)->toDateString(),
                '--to' => max($semua)->toDateString(),
            ]);
        }

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Satu tanggal, atau rentang "2026-09-01..2026-09-30".
     *
     * Rentang ada karena ada posisi yang masuk hampir setiap hari (Logistik),
     * dan menulis 30 pasangan tanggal=shift satu per satu adalah undangan untuk
     * kelewat satu tanpa ada yang sadar — tepat jenis kesalahan yang baru
     * ketahuan waktu orangnya terlanjur tercatat Alpha.
     *
     * @return list<Carbon>
     */
    protected function tanggalDari(string $teks): array
    {
        if (! str_contains($teks, '..')) {
            return [DateInput::parseOrFail($teks, 'tanggal')];
        }

        [$dari, $sampai] = explode('..', $teks, 2);

        $mulai = DateInput::parseOrFail(trim($dari), 'tanggal awal');
        $akhir = DateInput::parseOrFail(trim($sampai), 'tanggal akhir');

        if ($akhir->lessThan($mulai)) {
            throw new \InvalidArgumentException("Rentang terbalik: \"{$teks}\".");
        }

        $hari = (int) $mulai->diffInDays($akhir) + 1;

        // Batas waras. Salah ketik tahun ("2027-09-30") tanpa ini akan membuat
        // ribuan baris roster tanpa satu pun peringatan.
        if ($hari > self::MAX_HARI) {
            throw new \InvalidArgumentException(
                "Rentang {$hari} hari melebihi batas ".self::MAX_HARI.' hari. Pecah jadi beberapa perintah kalau memang disengaja.'
            );
        }

        $tanggal = [];

        for ($t = $mulai->copy(); $t->lessThanOrEqualTo($akhir); $t->addDay()) {
            $tanggal[] = $t->copy();
        }

        return $tanggal;
    }

    protected function jadwalSaatIni(Employee $employee, Carbon $tanggal): string
    {
        $baris = RosterAssignment::with('shift')
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $tanggal)
            ->get();

        if ($baris->isEmpty()) {
            return '(belum ada)';
        }

        return $baris->map(fn ($a) => $a->shift?->name ?? 'LIBUR')->implode(' + ');
    }
}
