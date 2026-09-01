<?php

namespace App\Console\Commands;

use App\Enums\AssignmentStatus;
use App\Models\Division;
use App\Models\Employee;
use App\Models\RosterAssignment;
use App\Models\Shift;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tampilkan roster satu bulan sebagai kalender, satu baris per orang.
 *
 * Selama ini roster cuma bisa DIUBAH dari terminal, tidak bisa DIBACA — satu-
 * satunya cara melihatnya adalah membuka halaman Roster di layar. Akibatnya
 * pertanyaan yang paling wajar ("bulan lalu polanya bagaimana?") tidak bisa
 * dijawab tanpa memelototi kalender, dan menyusun bulan berikutnya jadi
 * menebak-nebak.
 *
 * Sengaja padat: satu huruf per hari, supaya pola empat mingguan dan hari libur
 * yang bolong kelihatan sebagai bentuk, bukan sebagai daftar yang harus dibaca
 * satu per satu.
 */
class ShowRoster extends Command
{
    protected $signature = 'roster:lihat
                            {bulan : Bulan (YYYY-MM), mis. 2026-08}
                            {--pin= : Tampilkan satu orang saja}
                            {--divisi= : Saring per kode divisi, mis. kasir}';

    protected $description = 'Tampilkan roster satu bulan sebagai kalender per orang';

    /** Huruf per shift. Sisanya memakai huruf pertama kode shift. */
    protected const HURUF = ['pagi' => 'P', 'malam' => 'S', 'middle' => 'T'];

    public function handle(): int
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', trim((string) $this->argument('bulan')), $m)) {
            $this->error('Format bulan harus YYYY-MM, mis. 2026-08.');

            return self::FAILURE;
        }

        [$tahun, $bulan] = [(int) $m[1], (int) $m[2]];

        if ($bulan < 1 || $bulan > 12) {
            $this->error("Bulan {$bulan} tidak ada.");

            return self::FAILURE;
        }

        $timezone = config('attendance.timezone', 'Asia/Jakarta');
        $awal = Carbon::create($tahun, $bulan, 1, 0, 0, 0, $timezone);
        $akhir = $awal->copy()->endOfMonth();
        $jumlahHari = (int) $akhir->day;

        $karyawan = $this->karyawan();

        if ($karyawan->isEmpty()) {
            $this->error('Tidak ada karyawan yang cocok dengan saringan itu.');

            return self::FAILURE;
        }

        $baris = RosterAssignment::query()
            ->with('shift')
            ->whereIn('employee_id', $karyawan->pluck('id'))
            ->whereBetween('work_date', [$awal->copy()->startOfDay(), $akhir->copy()->endOfDay()])
            ->get()
            ->groupBy('employee_id');

        $this->newLine();
        $this->info($awal->translatedFormat('F Y').' — roster');
        $this->newLine();

        $lebarNama = 24;

        // Dua baris kepala: nomor tanggal, lalu huruf awal nama harinya. Nama
        // hari yang ikut ditampilkan membuat pola mingguan langsung kelihatan
        // tanpa harus menghitung tanggal di kepala.
        $this->line(str_repeat(' ', $lebarNama).$this->kepalaTanggal($awal, $jumlahHari));
        $this->line(str_repeat(' ', $lebarNama).$this->kepalaHari($awal, $jumlahHari));

        foreach ($karyawan as $orang) {
            $milik = ($baris[$orang->id] ?? collect())->keyBy(fn (RosterAssignment $a) => (int) $a->work_date->day);

            $this->line(
                $this->ratakan($this->namaPendek($orang, $lebarNama - 1), $lebarNama)
                .$this->barisKalender($milik, $jumlahHari)
            );
        }

        $this->newLine();
        $this->line('  '.$this->legenda());
        $this->newLine();
        $this->line('  <fg=gray>Titik berarti TIDAK ADA baris roster — beda dari libur:</>');
        $this->line('  <fg=gray>tidak masuk pada hari titik tidak pernah terhitung alpha.</>');
        $this->newLine();

        return self::SUCCESS;
    }

    /** @return Collection<int, Employee> */
    protected function karyawan(): Collection
    {
        $query = Employee::query()->tracked()->employed()->with('divisions');

        if ($pin = $this->option('pin')) {
            $query->where('pin_device', (string) $pin);
        }

        if ($kode = $this->option('divisi')) {
            $divisi = Division::where('code', $kode)->first();

            if ($divisi === null) {
                $this->error("Divisi '{$kode}' tidak ada. Yang tersedia: ".Division::pluck('code')->implode(', '));

                return collect();
            }

            $query->whereHas('divisions', fn ($q) => $q->where('divisions.id', $divisi->id));
        }

        return $query->orderBy('name')->get();
    }

    protected function kepalaTanggal(Carbon $awal, int $jumlahHari): string
    {
        $teks = '';

        for ($h = 1; $h <= $jumlahHari; $h++) {
            $teks .= sprintf('%2d ', $h);
        }

        return $teks;
    }

    protected function kepalaHari(Carbon $awal, int $jumlahHari): string
    {
        $teks = '';

        for ($h = 1; $h <= $jumlahHari; $h++) {
            $hari = $awal->copy()->day($h);

            // Minggu ditandai supaya batas pekan kelihatan.
            $huruf = mb_substr($hari->translatedFormat('D'), 0, 1);

            $teks .= $this->sel($huruf, $hari->isSunday() ? 'red' : null);
        }

        return $teks;
    }

    /** @param  Collection<int, RosterAssignment>  $milik */
    protected function barisKalender(Collection $milik, int $jumlahHari): string
    {
        $teks = '';

        for ($h = 1; $h <= $jumlahHari; $h++) {
            [$huruf, $warna] = $this->huruf($milik->get($h));

            $teks .= $this->sel($huruf, $warna);
        }

        return $teks;
    }

    /**
     * Satu sel selebar tiga karakter.
     *
     * Warna dibungkus SETELAH perataan, bukan sebelum: tag seperti
     * `<fg=gray>` ikut terhitung panjang oleh sprintf padahal tidak memakan
     * ruang di layar, jadi setiap sel berwarna akan menggeser seluruh kolom
     * sesudahnya dan kalendernya berhenti bisa dibaca sebagai kalender.
     */
    protected function sel(string $isi, ?string $warna): string
    {
        // Diratakan per KARAKTER, bukan per byte. sprintf('%2s') menghitung
        // byte, jadi satu titik tengah (·, dua byte) tidak kebagian spasi dan
        // seluruh kolom sesudahnya bergeser — kalendernya berhenti sejajar
        // persis di sel yang paling sering muncul.
        $teks = str_repeat(' ', max(0, 2 - mb_strlen($isi))).$isi.' ';

        return $warna === null ? $teks : "<fg={$warna}>{$teks}</>";
    }

    /** @return array{0: string, 1: ?string} huruf dan warnanya */
    protected function huruf(?RosterAssignment $baris): array
    {
        if ($baris === null) {
            return ['·', 'gray'];
        }

        return match ($baris->status) {
            AssignmentStatus::Leave => ['C', 'magenta'],
            AssignmentStatus::Holiday => ['N', 'cyan'],
            AssignmentStatus::Cancelled => ['x', 'gray'],
            AssignmentStatus::Off => ['L', 'gray'],
            default => [$this->hurufShift($baris->shift), null],
        };
    }

    protected function hurufShift(?Shift $shift): string
    {
        if ($shift === null) {
            return '<fg=gray>L</>';
        }

        return self::HURUF[$shift->code] ?? mb_strtoupper(mb_substr($shift->code, 0, 1));
    }

    protected function legenda(): string
    {
        $bagian = Shift::query()->orderBy('start_time')->get()
            ->map(fn (Shift $s) => $this->hurufShift($s).'='.$s->name)
            ->all();

        $bagian[] = 'L=libur';
        $bagian[] = 'C=cuti';
        $bagian[] = 'N=libur nasional';
        $bagian[] = '·=belum dijadwalkan';

        return implode('  ', $bagian);
    }

    /** mb_str_pad baru ada di PHP 8.3, dan versi server tidak dijamin. */
    protected function ratakan(string $teks, int $lebar): string
    {
        $kurang = max(0, $lebar - mb_strlen($teks));

        return $teks.str_repeat(' ', $kurang);
    }

    /**
     * Nama yang dipotong, tapi PIN-nya TIDAK PERNAH ikut terpotong.
     *
     * PIN yang hilang membuat baris ini tidak bisa dipakai menyusun perintah
     * perbaikan — dan mencocokkan orang dari kemiripan nama sudah pernah bikin
     * orang Kitchen masuk rotasi waiters selama enam minggu.
     */
    protected function namaPendek(Employee $orang, int $lebar): string
    {
        $ekor = ' ('.($orang->pin_device ?? '—').')';
        $ruang = $lebar - mb_strlen($ekor);

        $nama = mb_strlen($orang->name) > $ruang
            ? mb_substr($orang->name, 0, max(1, $ruang - 1)).'…'
            : $orang->name;

        return $nama.$ekor;
    }
}
