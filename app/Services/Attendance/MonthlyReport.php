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
        public readonly int $year,
        public readonly int $month,
    ) {}

    public static function for(int $year, int $month): self
    {
        return new self($year, $month);
    }

    public function periodeAwal(): Carbon
    {
        $awalBulan = Carbon::create($this->year, $this->month, 1, 0, 0, 0, config('attendance.timezone'));

        $mulaiPelacakan = config('attendance.tracking_starts_on');

        if ($mulaiPelacakan === null) {
            return $awalBulan;
        }

        // Cuma relevan kalau tanggal mulai jatuh di bulan yang sama dengan
        // yang diminta — periode dimulai dari situ, bukan tanggal 1, supaya
        // bulan pertama sistem ini jalan tidak dipenuhi alpha dari sebelum
        // mesin terpasang. Bulan-bulan lain (sebelum atau sesudah) tidak
        // disentuh: bulan sebelumnya memang tidak akan punya data sama
        // sekali (AttendanceComputer melewatinya), jadi tanggal 1 biasa
        // sudah cukup — whereBetween otomatis kosong.
        $mulai = Carbon::parse($mulaiPelacakan, config('attendance.timezone'))->startOfDay();

        return $mulai->isSameMonth($awalBulan) ? $mulai : $awalBulan;
    }

    public function periodeAkhir(): Carbon
    {
        return $this->periodeAwal()->endOfMonth();
    }

    public function judulPeriode(): string
    {
        return $this->periodeAwal()->translatedFormat('F Y');
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
     * Nama berkas unduhan.
     */
    public function namaBerkas(): string
    {
        return sprintf('absensi-%04d-%02d.xlsx', $this->year, $this->month);
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
