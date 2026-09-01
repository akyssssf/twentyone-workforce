<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Services\Roster\RosterValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Periksa kewajaran roster satu bulan dari terminal.
 *
 * `RosterValidator` sudah ada sejak lama dan sudah memeriksa hal-hal yang
 * paling mahal — termasuk "punya dua shift yang bertabrakan pada tanggal X",
 * yang persis kasus baris dobel yang diam-diam merebut scan lalu menghasilkan
 * telat berjam-jam palsu. Masalahnya tidak pernah ada cara MENJALANKANNYA dari
 * terminal, jadi seluruh pemeriksaan itu cuma hidup di jalur publish di layar.
 * Roster yang sudah terbit lalu berubah karena tukar shift, pengganti cuti,
 * atau koreksi manual tidak pernah diperiksa lagi.
 *
 * Ditambah satu pemeriksaan yang belum ada di validator: karyawan aktif yang
 * TIDAK punya baris roster sama sekali sepanjang bulan itu. Itu bukan sekadar
 * kolom kosong — tanpa baris roster, tidak masuk kerja tidak terhitung alpha
 * (lihat kebijakan sementara di 4.1), jadi ketidakhadiran mereka tidak pernah
 * kelihatan oleh siapa pun.
 */
class CheckRoster extends Command
{
    protected $signature = 'roster:periksa
                            {bulan : Bulan yang diperiksa (YYYY-MM), mis. 2026-09}';

    protected $description = 'Periksa kewajaran roster satu bulan: bentrok, kurang tenaga, dan yang belum dijadwalkan';

    public function handle(RosterValidator $validator): int
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', trim((string) $this->argument('bulan')), $m)) {
            $this->error('Format bulan harus YYYY-MM, mis. 2026-09.');

            return self::FAILURE;
        }

        [$tahun, $bulan] = [(int) $m[1], (int) $m[2]];

        if ($bulan < 1 || $bulan > 12) {
            $this->error("Bulan {$bulan} tidak ada.");

            return self::FAILURE;
        }

        $roster = Roster::query()
            ->where('branch_id', Branch::current()->id)
            ->where('period_year', $tahun)
            ->where('period_month', $bulan)
            ->first();

        $awal = Carbon::create($tahun, $bulan, 1, 0, 0, 0, config('attendance.timezone', 'Asia/Jakarta'));
        $akhir = $awal->copy()->endOfMonth();

        $this->newLine();
        $this->info($awal->translatedFormat('F Y').' — pemeriksaan roster');

        if ($roster === null) {
            $this->newLine();
            $this->error('Belum ada roster untuk bulan ini sama sekali.');

            return self::FAILURE;
        }

        $this->line('  status roster: '.$roster->status->value
            .($roster->published_at ? ' (terbit '.$roster->published_at->translatedFormat('d M Y').')' : ''));

        $hasil = $validator->validate($roster);

        $this->kelompok('MASALAH (memblokir)', $hasil['errors'], 'red');
        $this->kelompok('PERINGATAN', $hasil['warnings'], 'yellow');

        $belum = $this->belumDijadwalkan($awal, $akhir);

        $this->newLine();

        if ($belum->isNotEmpty()) {
            $this->line('<comment>BELUM DIJADWALKAN SAMA SEKALI BULAN INI</comment>');

            foreach ($belum as $orang) {
                $this->line(sprintf('  <fg=red>%s</> (PIN %s, %s)',
                    $orang->name,
                    $orang->pin_device ?? '—',
                    $orang->primaryDivision()?->name ?? 'tanpa divisi',
                ));
            }

            $this->newLine();
            $this->line('  Tanpa baris roster, tidak masuk kerja TIDAK terhitung alpha — jadi');
            $this->line('  ketidakhadiran mereka tidak akan pernah kelihatan. Isi dengan');
            $this->line('  <info>roster:set PIN '.$awal->toDateString().'..'.$akhir->toDateString().'=pagi</info>');
        } else {
            $this->info('Semua karyawan aktif punya jadwal bulan ini.');
        }

        $this->newLine();
        $this->line(sprintf('Ringkasan: %d masalah, %d peringatan, %d orang belum dijadwalkan.',
            $hasil['errors']->count(), $hasil['warnings']->count(), $belum->count()));
        $this->newLine();

        return $hasil['errors']->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    protected function kelompok(string $judul, Collection $isi, string $warna): void
    {
        $this->newLine();

        if ($isi->isEmpty()) {
            $this->line("<comment>{$judul}</comment>  —  tidak ada");

            return;
        }

        $this->line("<comment>{$judul}</comment>");

        foreach ($isi as $baris) {
            $this->line("  <fg={$warna}>•</> ".($baris['message'] ?? json_encode($baris)));
        }
    }

    /**
     * Karyawan yang ikut diabsen tapi tidak punya SATU pun baris roster bulan
     * itu. Admin dan akun test dikecualikan: mereka memang tidak pernah
     * dijadwalkan, dan memasukkannya cuma bikin daftar ini bising lalu diabaikan.
     *
     * @return Collection<int, Employee>
     */
    protected function belumDijadwalkan(Carbon $awal, Carbon $akhir): Collection
    {
        $terjadwal = RosterAssignment::query()
            ->whereBetween('work_date', [$awal->copy()->startOfDay(), $akhir->copy()->endOfDay()])
            ->distinct()
            ->pluck('employee_id');

        return Employee::query()
            ->tracked()
            ->employed()
            ->whereNotIn('id', $terjadwal)

            // Yang baru bergabung setelah bulan ini lewat bukan kelalaian.
            ->where(fn ($q) => $q->whereNull('joined_at')->orWhere('joined_at', '<=', $akhir))
            ->with('divisions')
            ->orderBy('name')
            ->get();
    }
}
