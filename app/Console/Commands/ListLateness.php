<?php

namespace App\Console\Commands;

use App\Enums\RuleType;
use App\Models\Attendance;
use App\Services\Payroll\PayrollPeriodFactory;
use App\Services\Rules\RuleResolver;
use App\Support\DateInput;
use App\Support\Durasi;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rincian telat PER ORANG: tiap kejadian (tanggal, shift, jam masuk), jumlahnya,
 * dan estimasi potongannya menurut aturan yang berlaku pada tanggal itu.
 *
 * attendance:daftar menjawab "siapa yang telat ≥ sekian" sebagai daftar datar.
 * Yang sering ditanyakan justru "telat apa saja yang dia punya bulan ini" —
 * yaitu satu orang, semua kejadiannya, dan angka yang akan masuk slip.
 *
 * Telat yang masih dalam toleransi ditampilkan terpisah (menitnya nol, tidak
 * dipotong), supaya jelas kenapa seseorang "datang lewat" tapi slipnya bersih.
 */
class ListLateness extends Command
{
    protected $signature = 'attendance:telat
                            {--from= : Tanggal awal (bawaan: awal periode gajian berjalan)}
                            {--to= : Tanggal akhir (bawaan: hari ini)}
                            {--pin= : Hanya karyawan ini}
                            {--min=1 : Hanya kejadian yang telatnya minimal sekian menit}';

    protected $description = 'Rincian telat tiap orang beserta estimasi potongannya';

    public function handle(PayrollPeriodFactory $periods, RuleResolver $rules): int
    {
        $timezone = config('attendance.timezone', 'Asia/Jakarta');

        $dari = $this->option('from')
            ? DateInput::parseOrFail((string) $this->option('from'), 'from')
            : $periods->covering(Carbon::today($timezone))->start_date->copy();

        $sampai = $this->option('to')
            ? DateInput::parseOrFail((string) $this->option('to'), 'to')
            : Carbon::today($timezone);

        if ($sampai->lessThan($dari)) {
            $this->error('--to lebih awal dari --from.');

            return self::FAILURE;
        }

        $min = max(0, (int) $this->option('min'));
        $toleransi = Settings::int('attendance.late_tolerance_minutes');

        $baris = Attendance::query()
            ->with(['employee.divisions', 'shift'])
            ->where('late_seconds', '>', 0)
            ->whereBetween('work_date', [$dari->copy()->startOfDay(), $sampai->copy()->endOfDay()])
            ->when($this->option('pin') !== null, fn ($q) => $q->whereHas(
                'employee',
                fn ($e) => $e->where('pin_device', (string) $this->option('pin')),
            ))
            ->orderBy('work_date')
            ->get();

        // Yang di dalam toleransi menitnya nol: tetap diikutkan supaya terlihat,
        // tapi filter --min hanya berlaku untuk yang benar-benar dihitung.
        $dihitung = $baris->filter(fn (Attendance $a) => $a->late_minutes >= max(1, $min));
        $dalamToleransi = $baris->filter(fn (Attendance $a) => $a->late_minutes === 0);

        $this->newLine();
        $this->line(sprintf('Telat %s s/d %s — %d kejadian dihitung, %d dalam toleransi %d menit (tidak dipotong)',
            $dari->toDateString(), $sampai->toDateString(), $dihitung->count(), $dalamToleransi->count(), $toleransi));

        if ($dihitung->isEmpty()) {
            $this->newLine();
            $this->info('Tidak ada telat yang dihitung pada rentang ini.');

            return self::SUCCESS;
        }

        $ringkas = [];

        foreach ($dihitung->groupBy('employee_id') as $kejadian) {
            /** @var Collection<int, Attendance> $kejadian */
            $emp = $kejadian->first()->employee;
            $total = 0;
            $rupiah = 0;
            $rows = [];

            foreach ($kejadian as $a) {
                $hasil = $rules->calculate(RuleType::Late, $a->work_date, (int) $a->late_minutes);
                $rupiah += $hasil['amount'];
                $total += (int) $a->late_minutes;

                $rows[] = [
                    $a->work_date->translatedFormat('D d M'),
                    $a->shift?->name ?? '-',
                    $a->scheduled_in?->copy()->setTimezone($timezone)->format('H:i') ?? '-',
                    $a->check_in_at?->copy()->setTimezone($timezone)->format('H:i:s') ?? '-',
                    Durasi::menit((int) $a->late_minutes),
                    $hasil['amount'] > 0 ? 'Rp '.number_format($hasil['amount'], 0, ',', '.') : '—',
                ];
            }

            $this->newLine();
            $this->line(sprintf('<options=bold>%s</> (PIN %s, %s)', $emp?->name, $emp?->pin_device, $emp?->primaryDivision()?->name ?? '-'));
            $this->table(['Tanggal', 'Shift', 'Jadwal', 'Masuk', 'Telat', 'Potongan'], $rows);
            $this->line(sprintf('  %dx telat · total %s · estimasi potongan Rp %s',
                $kejadian->count(), Durasi::menit($total), number_format($rupiah, 0, ',', '.')));

            $ringkas[] = [$emp?->name, $kejadian->count(), $total, $rupiah];
        }

        usort($ringkas, fn ($x, $y) => $y[2] <=> $x[2]);

        $this->newLine();
        $this->line('<options=bold>Ringkasan, urut dari yang paling lama telatnya</>');
        $this->table(['Nama', 'Kejadian', 'Total telat', 'Estimasi potongan'], array_map(fn ($r) => [
            $r[0], $r[1].'x', Durasi::menit($r[2]), 'Rp '.number_format($r[3], 0, ',', '.'),
        ], $ringkas));

        $this->line('Estimasi memakai aturan telat yang berlaku pada tanggal masing-masing; angka final ada di slip.');
        $this->line('Telat yang jam masuknya mendekati jam PULANG shift biasanya jadwal yang salah: cek dengan attendance:jelaskan PIN TANGGAL');

        return self::SUCCESS;
    }
}
