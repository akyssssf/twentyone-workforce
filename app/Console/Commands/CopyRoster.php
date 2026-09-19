<?php

namespace App\Console\Commands;

use App\Enums\AssignmentStatus;
use App\Models\Division;
use App\Models\Employee;
use App\Models\RosterAssignment;
use App\Services\Roster\RosterService;
use App\Support\DateInput;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Salin roster dari satu rentang tanggal ke rentang lain, digeser hari per hari.
 *
 * Untuk "bulan depan ulangi pola bulan ini". Sumbernya data roster yang
 * sekarang tersimpan — bukan tabel yang diingat siapa pun — jadi yang disalin
 * adalah keadaan sebenarnya, termasuk koreksi yang sudah dilakukan.
 *
 * Yang sengaja TIDAK disalin: baris cuti dan baris batal. Cuti adalah
 * keputusan untuk tanggal tertentu, bukan pola; menyalinnya berarti mencutikan
 * orang di bulan depan tanpa ada yang mengajukan. Libur biasa ikut disalin,
 * karena itu bagian dari polanya.
 *
 * Geserannya harus kelipatan 7 hari kalau polanya mingguan — 28 hari untuk
 * rotasi 4 minggu — supaya Senin jatuh ke Senin. Perintah ini memperingatkan
 * kalau bukan, tapi tidak melarang: ada pola yang memang tidak mingguan.
 */
class CopyRoster extends Command
{
    protected $signature = 'roster:salin
                            {--dari= : Rentang sumber, mis. 2026-09-03..2026-09-23}
                            {--ke= : Tanggal tujuan pertama, mis. 2026-10-01}
                            {--pin= : Hanya satu orang}
                            {--divisi= : Hanya satu divisi (kode)}
                            {--recompute : Hitung ulang absensi rentang tujuan}
                            {--ya : Lewati konfirmasi}';

    protected $description = 'Salin roster dari satu rentang tanggal ke rentang lain';

    public function handle(RosterService $service): int
    {
        if (! $this->option('dari') || ! $this->option('ke')) {
            $this->error('Isi --dari (rentang sumber, A..B) dan --ke (tanggal tujuan pertama).');

            return self::FAILURE;
        }

        if (! str_contains((string) $this->option('dari'), '..')) {
            $this->error('--dari harus rentang, mis. 2026-09-03..2026-09-23.');

            return self::FAILURE;
        }

        [$a, $b] = explode('..', (string) $this->option('dari'), 2);
        $sumberMulai = DateInput::parseOrFail(trim($a), 'dari (awal)');
        $sumberAkhir = DateInput::parseOrFail(trim($b), 'dari (akhir)');
        $tujuanMulai = DateInput::parseOrFail((string) $this->option('ke'), 'ke');

        if ($sumberAkhir->lessThan($sumberMulai)) {
            $this->error('Rentang --dari terbalik.');

            return self::FAILURE;
        }

        $geser = (int) $sumberMulai->diffInDays($tujuanMulai, false);
        $hari = (int) $sumberMulai->diffInDays($sumberAkhir) + 1;
        $tujuanAkhir = $tujuanMulai->copy()->addDays($hari - 1);

        // Tumpang tindih ke arah mana pun: menyalin sambil menimpa sumbernya
        // sendiri menghasilkan campuran yang tidak bisa dijelaskan.
        if ($tujuanMulai->lessThanOrEqualTo($sumberAkhir) && $tujuanAkhir->greaterThanOrEqualTo($sumberMulai)) {
            $this->error('Rentang tujuan menimpa rentang sumbernya sendiri.');

            return self::FAILURE;
        }

        $karyawan = $this->karyawan();

        if ($karyawan === null) {
            return self::FAILURE;
        }

        $this->line(sprintf('Sumber : %s s/d %s (%d hari)', $sumberMulai->toDateString(), $sumberAkhir->toDateString(), $hari));
        $this->line(sprintf('Tujuan : %s s/d %s (geser %d hari)', $tujuanMulai->toDateString(), $tujuanAkhir->toDateString(), $geser));
        $this->line('Orang  : '.$karyawan->count());

        if ($geser % 7 !== 0) {
            $this->newLine();
            $this->warn("Geseran {$geser} hari bukan kelipatan 7: hari Senin di sumber TIDAK jatuh ke Senin di tujuan.");
            $this->line('  Untuk pola mingguan pakai 7, 14, 21, atau 28 hari.');
        }

        $sumber = RosterAssignment::query()
            ->with(['shift', 'division'])
            ->whereIn('employee_id', $karyawan->pluck('id'))
            ->whereBetween('work_date', [$sumberMulai->copy()->startOfDay(), $sumberAkhir->copy()->endOfDay()])
            ->get();

        if ($sumber->isEmpty()) {
            $this->error('Tidak ada baris roster di rentang sumber untuk orang-orang itu.');

            return self::FAILURE;
        }

        $disalin = $sumber->filter(fn (RosterAssignment $r) => in_array($r->status, [AssignmentStatus::Scheduled, AssignmentStatus::Off], true));
        $dilewati = $sumber->count() - $disalin->count();

        $this->newLine();
        $this->line("{$disalin->count()} baris akan disalin".($dilewati > 0 ? ", {$dilewati} baris cuti/batal dilewati" : '').'.');

        if (! $this->option('ya') && ! $this->confirm('Lanjutkan?', false)) {
            $this->line('Dibatalkan, tidak ada yang disalin.');

            return self::SUCCESS;
        }

        $berhasil = 0;
        $gagal = [];

        foreach ($disalin as $r) {
            $tujuan = $r->work_date->copy()->addDays($geser);

            try {
                $service->assign(
                    $service->findOrCreate((int) $tujuan->year, (int) $tujuan->month),
                    $r->employee,
                    $tujuan,
                    $r->shift_id,
                    $r->division_id,
                );
                $berhasil++;
            } catch (RuntimeException $e) {
                // Biasanya: tujuan sudah cuti yang disetujui. Itu memang tidak
                // boleh ditimpa, dan dilaporkan supaya tidak dikira tersalin.
                $gagal[] = sprintf('%s %s: %s', $r->employee?->name, $tujuan->toDateString(), $e->getMessage());
            }
        }

        $this->newLine();
        $this->info("{$berhasil} baris tersalin.");

        foreach ($gagal as $g) {
            $this->line('  <fg=red>gagal</> '.$g);
        }

        if ($this->option('recompute')) {
            Artisan::call('attendance:compute', [
                '--from' => $tujuanMulai->toDateString(),
                '--to' => $tujuanAkhir->toDateString(),
            ]);
            $this->line('Absensi rentang tujuan dihitung ulang.');
        }

        $this->newLine();
        $this->line('Periksa hasilnya: <info>php artisan roster:lihat '.$tujuanMulai->format('Y-m').'</info> lalu <info>php artisan roster:periksa '.$tujuanMulai->format('Y-m').'</info>');

        return $gagal === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return ?Collection<int, Employee> */
    protected function karyawan(): ?Collection
    {
        $query = Employee::query()->tracked()->employed();

        if ($pin = $this->option('pin')) {
            $query->where('pin_device', (string) $pin);
        }

        if ($kode = $this->option('divisi')) {
            $divisi = Division::where('code', $kode)->first();

            if ($divisi === null) {
                $this->error("Divisi '{$kode}' tidak ada. Yang tersedia: ".Division::pluck('code')->implode(', '));

                return null;
            }

            $query->whereHas('divisions', fn ($q) => $q->where('divisions.id', $divisi->id));
        }

        $hasil = $query->get();

        if ($hasil->isEmpty()) {
            $this->error('Tidak ada karyawan yang cocok.');

            return null;
        }

        return $hasil;
    }
}
