<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Attendance\AttendanceComputer;
use App\Services\Attendance\WorkWindow;
use App\Support\DateInput;
use App\Support\Durasi;
use App\Support\OperationalDate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sapu seluruh karyawan, cari rekap yang jam masuknya BUKAN scan pertama.
 *
 * `attendance:jelaskan` menjawab "kenapa punya si A begini" — tapi itu baru
 * berguna kalau sudah tahu si A yang bermasalah. Kegagalan di alur ini justru
 * tidak menimbulkan error dan tidak kelihatan di rekap, jadi yang ketahuan cuma
 * yang kebetulan protes. Perintah ini yang mencari sisanya.
 *
 * Dua sebab yang dicari, keduanya berujung sama — jam masuk seseorang tercatat
 * lebih siang daripada kedatangannya yang sebenarnya:
 *
 *   1. scan paling awal jatuh DI LUAR jendela kerja lalu dibuang diam-diam
 *      (jendela ikut bergeser kalau shift-nya dapat jam khusus, atau kalau
 *      orangnya terjadwal di shift yang bukan shift yang benar-benar dia
 *      jalani — misalnya tukar shift yang tidak pernah masuk sistem);
 *   2. scan paling awal ADA di dalam jendela tapi tetap tidak dipilih, yang
 *      cuma mungkin terjadi kalau strategi jam masuk memakai tombol fungsi
 *      mesin (status_scan) dan tombolnya tidak ditekan konsisten.
 */
class AuditDiscardedScans extends Command
{
    protected $signature = 'attendance:periksa
                            {--from= : Tanggal awal (YYYY-MM-DD), kosong berarti hari ini}
                            {--to= : Tanggal akhir, kosong berarti sama dengan --from}';

    protected $description = 'Cari rekap yang jam masuknya bukan scan pertama karyawan';

    public function handle(AttendanceComputer $computer): int
    {
        $dari = $this->option('from')
            ? DateInput::parseOrFail((string) $this->option('from'), 'from')
            : OperationalDate::today();

        $sampai = $this->option('to')
            ? DateInput::parseOrFail((string) $this->option('to'), 'to')
            : $dari->copy();

        if ($sampai->lessThan($dari)) {
            $this->error('--to lebih awal dari --from.');

            return self::FAILURE;
        }

        $timezone = config('attendance.timezone', 'Asia/Jakarta');
        $karyawan = Employee::query()->tracked()->employed()->with('defaultShift')->get();

        $temuan = [];

        for ($tanggal = $dari->copy(); $tanggal->lessThanOrEqualTo($sampai); $tanggal->addDay()) {
            foreach ($karyawan as $orang) {
                $baris = $this->periksa($computer, $orang, $tanggal->copy(), $timezone);

                if ($baris !== null) {
                    $temuan[] = $baris;
                }
            }
        }

        $this->newLine();

        if ($temuan === []) {
            $this->info(sprintf('Tidak ada temuan untuk %s s/d %s — jam masuk semua orang memang scan pertamanya.',
                $dari->toDateString(), $sampai->toDateString()));

            return self::SUCCESS;
        }

        $this->warn(sprintf('%d rekap yang jam masuknya bukan scan pertama (%s s/d %s):',
            count($temuan), $dari->toDateString(), $sampai->toDateString()));
        $this->newLine();

        $this->table(
            ['Tanggal', 'Karyawan', 'PIN', 'Shift', 'Masuk di rekap', 'Scan lebih awal', 'Selisih', 'Sebab'],
            $temuan,
        );

        $this->newLine();
        $this->line('Lihat rinciannya per orang:  <info>php artisan attendance:jelaskan PIN TANGGAL</info>');
        $this->line('Kalau sebabnya "di luar jendela", yang salah biasanya JADWALNYA, bukan scannya —');
        $this->line('betulkan dengan <info>roster:set</info>, lalu pasang ulang <info>roster:jam-khusus</info> tanggal itu');
        $this->line('(override jam menempel per-orang, jadi orang yang baru pindah tidak ikut kebagian).');
        $this->newLine();

        return self::SUCCESS;
    }

    /** @return ?array<int, string> */
    protected function periksa(AttendanceComputer $computer, Employee $orang, Carbon $tanggal, string $timezone): ?array
    {
        $jejak = collect($computer->jejak($orang, $tanggal))
            ->filter(fn (array $b) => $b['shift'] !== null)
            ->values();

        if ($jejak->isEmpty()) {
            return null;
        }

        $semua = $computer->scanHarian($orang, $tanggal, $jejak->all());

        if ($semua->isEmpty()) {
            return null;
        }

        // Jam masuk paling awal di antara shift hari itu — orang bisa punya
        // dua baris kalau double shift, dan yang dibandingkan tentu yang
        // pertama.
        $checkIn = $jejak->pluck('check_in')->filter()->sort()->first();

        $paling_awal = $semua->first()->scanned_at->copy()->setTimezone($timezone);

        // Jam masuk sudah sama dengan scan pertama: tidak ada yang hilang.
        if ($checkIn !== null && $checkIn->equalTo($paling_awal)) {
            return null;
        }

        $diJendela = $semua->contains(
            fn ($log) => $this->adaJendela($log->scanned_at->copy()->setTimezone($timezone), $jejak)
        ) && $this->adaJendela($paling_awal, $jejak);

        // Belum ada jam masuk sama sekali DAN scan pertamanya masih di dalam
        // jendela berarti hari itu memang belum selesai dihitung, bukan temuan.
        if ($checkIn === null && $diJendela) {
            return null;
        }

        $selisih = $checkIn !== null
            ? Durasi::detik($checkIn->getTimestamp() - $paling_awal->getTimestamp())
            : Durasi::KOSONG;

        return [
            $tanggal->toDateString(),
            $orang->name,
            (string) $orang->pin_device,
            $jejak->pluck('shift.name')->unique()->implode(' + '),
            $checkIn?->format('H:i:s') ?? '(tidak ada)',
            $paling_awal->format('H:i:s'),
            $selisih,
            $diJendela
                ? 'scan pertama ada di jendela tapi tidak dipilih'
                : 'scan pertama di luar jendela, dibuang',
        ];
    }

    /** @param  Collection<int, array<string, mixed>>  $jejak */
    protected function adaJendela(Carbon $waktu, Collection $jejak): bool
    {
        foreach ($jejak as $b) {
            /** @var WorkWindow $w */
            $w = $b['window'];

            if ($w->contains($waktu)) {
                return true;
            }
        }

        return false;
    }
}
