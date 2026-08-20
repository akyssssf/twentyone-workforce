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
                            {jadwal* : Pasangan tanggal=shift, mis. 2026-08-21=malam 2026-08-22=pagi 2026-08-23=libur}
                            {--divisi= : Kode divisi (kasir, waiter, chef, barista, logistik). Kosong = ikut divisi utamanya}
                            {--recompute : Hitung ulang absensi tanggal-tanggal itu}';

    protected $description = 'Ubah jadwal shift seseorang pada satu atau beberapa tanggal';

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

            $tanggal = DateInput::parseOrFail(trim($tgl), 'tanggal');
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

            $rencana[] = [$tanggal, $shift];
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

            foreach ($rencana as [$tanggal, $shift]) {
                Artisan::call('attendance:compute', [
                    '--from' => $tanggal->toDateString(),
                    '--to' => $tanggal->toDateString(),
                ]);
            }
        }

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
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
