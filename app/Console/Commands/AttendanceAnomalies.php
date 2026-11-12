<?php

namespace App\Console\Commands;

use App\Enums\AssignmentStatus;
use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Support\DateInput;
use App\Support\Durasi;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Satu layar untuk semua yang janggal di rekap: kandidat jadwal tertukar,
 * scan pulang hilang, kerja jauh melewati jadwal, dobel baris. Menggantikan
 * menempel scan semua orang tanggal demi tanggal.
 *
 * Hanya membaca rekap yang sudah dihitung; tidak mengubah apa pun. Jalankan
 * attendance:compute dulu untuk rentang yang sama kalau baru mengubah roster.
 */
class AttendanceAnomalies extends Command
{
    protected $signature = 'attendance:janggal
                            {--from= : Tanggal awal (YYYY-MM-DD), bawaan 14 hari lalu}
                            {--to= : Tanggal akhir (YYYY-MM-DD), bawaan hari ini}
                            {--telat=45 : Telat/pulang cepat minimal sekian menit dianggap janggal}
                            {--lebih=90 : Pulang melewati jadwal minimal sekian menit tanpa lembur dianggap janggal}';

    protected $description = 'Daftar kejanggalan absensi: jadwal tertukar, scan pulang hilang, dobel baris, kerja kelewat jadwal';

    public function handle(): int
    {
        $tz = config('attendance.timezone', 'Asia/Jakarta');

        $sampai = $this->option('to') ? DateInput::parseOrFail((string) $this->option('to'), 'to') : Carbon::today($tz);
        $dari = $this->option('from') ? DateInput::parseOrFail((string) $this->option('from'), 'from') : $sampai->copy()->subDays(14);

        if ($sampai->lessThan($dari)) {
            $this->error('--to lebih awal dari --from.');

            return self::FAILURE;
        }

        $telat = max(1, (int) $this->option('telat'));
        $lebih = max(1, (int) $this->option('lebih'));

        $semua = Attendance::query()
            ->with(['employee', 'shift', 'rosterAssignment'])
            ->whereBetween('work_date', [$dari->copy()->startOfDay(), $sampai->copy()->endOfDay()])
            ->orderBy('work_date')
            ->get();

        $jam = fn ($t) => $t?->copy()->setTimezone($tz)->format('H:i') ?? '—';
        $baris = fn (Attendance $a, string $cat) => [
            $a->work_date->format('d/m'),
            ($a->employee?->pin_device ?? '?').' '.$a->employee?->name,
            $a->shift?->name ?? '-',
            $jam($a->scheduled_in).'–'.$jam($a->scheduled_out),
            $jam($a->check_in_at).'–'.$jam($a->check_out_at),
            $cat,
        ];

        $kelompok = [
            'Jadwal diduga TERTUKAR (telat/pulang cepat besar)' => $semua
                ->filter(fn (Attendance $a) => $a->check_in_at !== null
                    && ($a->late_minutes >= $telat || $a->early_leave_minutes >= $telat))
                ->map(fn (Attendance $a) => $baris($a, trim(
                    ($a->late_minutes >= $telat ? 'telat '.Durasi::menit($a->late_minutes).' ' : '')
                    .($a->early_leave_minutes >= $telat ? 'pulang cepat '.Durasi::menit($a->early_leave_minutes) : '')
                ))),

            'Masuk tapi TANPA JADWAL kerja (kosong / libur)' => $semua
                ->filter(fn (Attendance $a) => $a->check_in_at !== null && (
                    $a->roster_assignment_id === null
                    || ($a->rosterAssignment !== null && $a->rosterAssignment->status !== AssignmentStatus::Scheduled)
                ))
                ->map(fn (Attendance $a) => $baris($a, $a->roster_assignment_id === null ? 'tidak ada baris roster' : 'roster '.$a->rosterAssignment->status->label())),

            'Scan masuk ADA, scan pulang HILANG' => $semua
                ->filter(fn (Attendance $a) => $a->check_in_at !== null && $a->check_out_at === null
                    && $a->status === AttendanceStatus::Hadir)
                ->map(fn (Attendance $a) => $baris($a, 'lupa scan pulang?')),

            'Pulang MELEWATI jadwal tanpa lembur tercatat' => $semua
                ->filter(fn (Attendance $a) => $a->check_out_at !== null && $a->scheduled_out !== null
                    && $a->overtime_minutes === 0
                    && $a->scheduled_out->diffInMinutes($a->check_out_at, false) >= $lebih)
                ->map(fn (Attendance $a) => $baris($a, 'lewat '.Durasi::menit((int) $a->scheduled_out->diffInMinutes($a->check_out_at)))),

            'DOBEL baris di hari yang sama (satu kosong)' => $semua
                ->groupBy(fn (Attendance $a) => $a->employee_id.'|'.$a->work_date->toDateString())
                ->filter(fn (Collection $g) => $g->count() > 1
                    && $g->contains(fn (Attendance $a) => $a->check_in_at === null)
                    && $g->contains(fn (Attendance $a) => $a->check_in_at !== null))
                ->flatten(1)
                ->map(fn (Attendance $a) => $baris($a, $a->check_in_at === null ? 'baris tanpa scan: '.$a->status->value : 'baris yang dipakai')),

            'ALPHA' => $semua
                ->filter(fn (Attendance $a) => $a->status === AttendanceStatus::Alpha)
                ->map(fn (Attendance $a) => $baris($a, 'alpha')),
        ];

        $ada = false;

        foreach ($kelompok as $judul => $rows) {
            $this->newLine();

            if ($rows->isEmpty()) {
                $this->line("<info>OK</info>  {$judul}: tidak ada");

                continue;
            }

            $ada = true;
            $this->line("<options=bold>{$judul}</> — {$rows->count()}");
            $this->table(['Tgl', 'Karyawan', 'Shift', 'Jadwal', 'Masuk–Pulang', 'Catatan'], $rows->values()->all());
        }

        $this->newLine();
        $this->line(sprintf('Rentang %s s/d %s, %d catatan rekap diperiksa.', $dari->toDateString(), $sampai->toDateString(), $semua->count()));

        if ($ada) {
            $this->line('Lihat scan mentahnya: php artisan attendance:jelaskan PIN TANGGAL');
        }

        return self::SUCCESS;
    }
}
