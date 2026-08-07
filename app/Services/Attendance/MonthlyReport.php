<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rekap sebulan per karyawan, bentuk yang dipakai halaman rekap maupun
 * berkas Excel.
 *
 * Dibaca dari tabel attendances, bukan dihitung ulang dari scan mentah. Jadi
 * angka di laporan selalu sama persis dengan yang tampil di dashboard; kalau
 * ada yang meleset, sumbernya satu dan tinggal jalankan attendance:compute.
 */
class MonthlyReport
{
    public function __construct(
        public readonly Carbon $awal,
        public readonly Carbon $akhir,
        public readonly string $granularitas = 'bulanan',
    ) {}

    public static function for(int $year, int $month): self
    {
        $timezone = config('attendance.timezone');
        $awalBulan = Carbon::create($year, $month, 1, 0, 0, 0, $timezone);

        $mulaiPelacakan = config('attendance.tracking_starts_on');
        $awal = $awalBulan;

        if ($mulaiPelacakan !== null) {
            // Cuma relevan kalau tanggal mulai jatuh di bulan yang sama
            // dengan yang diminta — periode dimulai dari situ, bukan
            // tanggal 1, supaya bulan pertama sistem ini jalan tidak
            // dipenuhi alpha dari sebelum mesin terpasang. Bulan-bulan lain
            // (sebelum atau sesudah) tidak disentuh: bulan sebelumnya
            // memang tidak akan punya data sama sekali (AttendanceComputer
            // melewatinya), jadi tanggal 1 biasa sudah cukup — whereBetween
            // otomatis kosong.
            $mulai = Carbon::parse($mulaiPelacakan, $timezone)->startOfDay();
            $awal = $mulai->isSameMonth($awalBulan) ? $mulai : $awalBulan;
        }

        return new self($awal, $awalBulan->copy()->endOfMonth(), 'bulanan');
    }

    /** Senin s/d Minggu yang memuat $tanggal. */
    public static function forWeek(Carbon $tanggal): self
    {
        $timezone = config('attendance.timezone');
        $hari = $tanggal->copy()->setTimezone($timezone)->startOfDay();

        return new self($hari->copy()->startOfWeek(), $hari->copy()->endOfWeek(), 'mingguan');
    }

    public static function forDay(Carbon $tanggal): self
    {
        $timezone = config('attendance.timezone');
        $hari = $tanggal->copy()->setTimezone($timezone)->startOfDay();

        return new self($hari->copy(), $hari->copy()->endOfDay(), 'harian');
    }

    public function periodeAwal(): Carbon
    {
        return $this->awal;
    }

    public function periodeAkhir(): Carbon
    {
        return $this->akhir;
    }

    public function judulPeriode(): string
    {
        return match ($this->granularitas) {
            'harian' => $this->awal->translatedFormat('l, d F Y'),
            'mingguan' => $this->awal->isSameMonth($this->akhir)
                ? $this->awal->translatedFormat('d').' – '.$this->akhir->translatedFormat('d F Y')
                : $this->awal->translatedFormat('d M').' – '.$this->akhir->translatedFormat('d M Y'),
            default => $this->awal->translatedFormat('F Y'),
        };
    }

    /**
     * Satu baris per karyawan aktif, termasuk yang tidak punya catatan sama
     * sekali bulan itu. Karyawan yang hilang dari laporan lebih berbahaya
     * daripada karyawan dengan angka nol, karena tidak ada yang menyadarinya.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function ringkasan(): Collection
    {
        $karyawan = Employee::with('defaultShift')->active()->orderBy('name')->get();

        $perKaryawan = Attendance::query()
            ->whereBetween('work_date', [$this->periodeAwal(), $this->periodeAkhir()])
            ->get()
            ->groupBy('employee_id');

        return $karyawan->map(function (Employee $employee) use ($perKaryawan) {
            /** @var Collection<int, Attendance> $catatan */
            $catatan = $perKaryawan->get($employee->id, collect());

            $totalTelatMenit = (int) $catatan->sum('late_minutes');
            $totalLemburMenit = (int) $catatan->sum('overtime_minutes');

            return [
                'employee' => $employee,
                'pin' => $employee->pin_device,
                'nama' => $employee->name,
                'shift' => $employee->defaultShift?->name ?? '-',

                'hadir' => $catatan->where('status', AttendanceStatus::Hadir)->count(),

                // Terlambat bukan status, melainkan hitungan baris yang
                // menitnya lebih dari nol. Orang yang sama bisa terhitung
                // Hadir sekaligus Terlambat — memang begitu kenyataannya.
                'telat' => $catatan->filter(fn ($a) => $a->late_minutes > 0)->count(),
                'pulang_cepat' => $catatan->filter(fn ($a) => $a->early_leave_minutes > 0)->count(),

                'alpha' => $catatan->where('status', AttendanceStatus::Alpha)->count(),
                'izin' => $catatan->where('status', AttendanceStatus::Izin)->count(),
                'sakit' => $catatan->where('status', AttendanceStatus::Sakit)->count(),
                'cuti' => $catatan->where('status', AttendanceStatus::Cuti)->count(),
                'libur' => $catatan->where('status', AttendanceStatus::Libur)->count(),

                'hari_tercatat' => $catatan->count(),
                'total_telat_detik' => (int) $catatan->sum('late_seconds'),
                'total_telat_menit' => $totalTelatMenit,
                'total_lembur_menit' => $totalLemburMenit,
            ];
        });
    }

    /**
     * Rincian harian, satu baris per karyawan per tanggal.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rincian(): Collection
    {
        return Attendance::with(['employee', 'shift'])
            ->whereBetween('work_date', [$this->periodeAwal(), $this->periodeAkhir()])
            ->get()
            ->sortBy([
                fn ($a, $b) => strcmp((string) $a->employee?->name, (string) $b->employee?->name),
                fn ($a, $b) => $a->work_date <=> $b->work_date,
            ])
            ->values()
            ->map(fn (Attendance $a) => [
                'tanggal' => $a->work_date,
                'pin' => $a->employee?->pin_device ?? '-',
                'nama' => $a->employee?->name ?? '-',
                'shift' => $a->shift?->name ?? '-',
                'jadwal' => $a->scheduled_in,
                'masuk' => $a->check_in_at,
                'pulang' => $a->check_out_at,
                'telat_detik' => $a->late_seconds,
                'telat_menit' => $a->late_minutes,
                'pulang_cepat_menit' => $a->early_leave_minutes,
                'status' => $a->status,
            ]);
    }

    /**
     * @return array<string, int>
     */
    public function total(): array
    {
        $ringkasan = $this->ringkasan();

        // Tidak ada satu pun kolom rupiah di sini, dan itu disengaja.
        // Laporan absensi melaporkan FAKTA (hari, menit); nominalnya urusan
        // modul payroll, yang menghitungnya sekali lalu membekukannya di slip.
        return [
            'karyawan' => $ringkasan->count(),
            'hadir' => (int) $ringkasan->sum('hadir'),
            'telat' => (int) $ringkasan->sum('telat'),
            'pulang_cepat' => (int) $ringkasan->sum('pulang_cepat'),
            'alpha' => (int) $ringkasan->sum('alpha'),
            'izin' => (int) $ringkasan->sum('izin'),
            'sakit' => (int) $ringkasan->sum('sakit'),
            'cuti' => (int) $ringkasan->sum('cuti'),
            'libur' => (int) $ringkasan->sum('libur'),
            'total_telat_menit' => (int) $ringkasan->sum('total_telat_menit'),
            'total_lembur_menit' => (int) $ringkasan->sum('total_lembur_menit'),
        ];
    }

    /**
     * Teks rekap dalam format markup WhatsApp (*tebal*, _miring_), siap
     * tempel apa adanya ke chat — bukan HTML yang perlu dirender.
     *
     * SENGAJA tidak pakai tabel rata kolom (blok monospace ```...```).
     * Itu terlihat rapi di preview desktop, tapi begitu WhatsApp membungkus
     * baris yang kepanjangan di layar HP yang sempit, kesejajaran kolomnya
     * hancur — nama pindah baris sendiri, angkanya di baris lain, jadi
     * lebih susah dibaca daripada tanpa tabel sama sekali. Satu baris teks
     * biasa per orang aman dibungkus di layar mana pun karena tidak ada
     * kesejajaran visual yang bisa rusak.
     */
    public function teksWhatsApp(): string
    {
        $baris = $this->ringkasan()->filter(
            fn (array $b) => $b['hari_tercatat'] > 0
        )->values();

        $total = $this->total();

        $teks = "*Rekap Absensi — {$this->judulPeriode()}*\n\n";

        if ($baris->isEmpty()) {
            $teks .= "_Belum ada data untuk periode ini._\n";

            return $teks;
        }

        foreach ($baris as $b) {
            $catatan = ["H{$b['hadir']}"];

            if ($b['telat'] > 0) {
                $catatan[] = "T{$b['telat']}";
            }
            if ($b['alpha'] > 0) {
                $catatan[] = "A{$b['alpha']}";
            }
            if ($b['libur'] > 0) {
                $catatan[] = "L{$b['libur']}";
            }

            $teks .= "• {$b['nama']} — ".implode(' ', $catatan)."\n";
        }

        $teks .= "\n_H=Hadir · T=Telat · A=Alpha · L=Libur_\n\n";
        $teks .= "*Total:* {$total['karyawan']} karyawan · {$total['hadir']} hadir · {$total['telat']} telat · {$total['alpha']} alpha";

        if ($total['total_lembur_menit'] > 0) {
            $teks .= ' · '.round($total['total_lembur_menit'] / 60, 1).' jam lembur';
        }

        return $teks;
    }

    /**
     * Nama berkas unduhan.
     */
    public function namaBerkas(): string
    {
        return match ($this->granularitas) {
            'harian' => 'absensi-'.$this->awal->format('Y-m-d').'.xlsx',
            'mingguan' => 'absensi-'.$this->awal->format('Y-m-d').'_'.$this->akhir->format('Y-m-d').'.xlsx',
            default => 'absensi-'.$this->awal->format('Y-m').'.xlsx',
        };
    }

    /**
     * Ubah detik jadi bentuk yang enak dibaca manusia di laporan.
     */
    public static function durasi(int $detik): string
    {
        if ($detik <= 0) {
            return '-';
        }

        $jam = intdiv($detik, 3600);
        $menit = intdiv($detik % 3600, 60);
        $sisa = $detik % 60;

        if ($jam > 0) {
            return "{$jam}j {$menit}m";
        }

        return $menit > 0 ? "{$menit}m {$sisa}d" : "{$sisa}d";
    }
}
