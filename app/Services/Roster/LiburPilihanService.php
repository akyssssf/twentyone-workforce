<?php

namespace App\Services\Roster;

use App\Enums\AssignmentStatus;
use App\Models\Employee;
use App\Models\RosterAssignment;
use App\Services\Payroll\PayrollLockGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Libur yang dipilih sendiri karyawan, dengan jatah per bulan.
 *
 * Dipakai posisi yang masuk hampir setiap hari (Logistik): jatahnya dihitung
 * per bulan, bukan per minggu, dan tanggalnya ditentukan orangnya sendiri.
 * Berbeda dari cuti — ini bukan hak yang dipotong dari saldo, melainkan hari
 * istirahat rutin yang kebetulan tanggalnya bebas.
 *
 * Berlaku LANGSUNG tanpa persetujuan manajer, karena itu dua hal jadi wajib:
 *
 *   1. jatahnya ditegakkan di sini, bukan dipercayakan ke tampilan — tombol
 *      yang disembunyikan tidak menghentikan siapa pun yang mengirim form-nya
 *      langsung;
 *   2. yang sudah terpakai tidak bisa dibatalkan sendiri. Kalau bisa,
 *      "jatah 2 hari" berubah jadi "boleh coba-coba sepuasnya", dan jadwal
 *      kafe ikut berubah tiap kali orangnya berubah pikiran.
 *
 * Libur yang dipasang admin lewat roster:set TIDAK memotong jatah: yang
 * dihitung cuma baris yang ditandai berasal dari pilihan sendiri.
 */
class LiburPilihanService
{
    public function __construct(
        protected RosterService $roster,
        protected PayrollLockGuard $lockGuard,
    ) {}

    /** Apakah karyawan ini memang memakai pola libur pilihan sendiri. */
    public function berlakuUntuk(Employee $employee): bool
    {
        $divisi = (array) config('attendance.libur_pilihan.divisi', []);

        return $employee->divisions->pluck('code')->intersect($divisi)->isNotEmpty();
    }

    public function jatah(): int
    {
        return (int) config('attendance.libur_pilihan.jatah_per_bulan', 2);
    }

    /**
     * Libur pilihan sendiri yang sudah dipakai pada bulan kalender $bulan.
     *
     * @return Collection<int, RosterAssignment>
     */
    public function terpakai(Employee $employee, Carbon $bulan): Collection
    {
        return RosterAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('source', config('attendance.libur_pilihan.source', 'pilihan'))
            ->whereNull('shift_id')

            // Batas atas eksplisit sampai detik terakhir: kolom work_date
            // tersimpan sebagai "Y-m-d 00:00:00", jadi whereBetween dengan
            // tanggal pendek MEMBUANG hari terakhir bulan itu — jatah orangnya
            // jadi terlihat masih sisa padahal sudah habis.
            ->whereBetween('work_date', [
                $bulan->copy()->startOfMonth(),
                $bulan->copy()->endOfMonth()->endOfDay(),
            ])
            ->orderBy('work_date')
            ->get();
    }

    public function sisa(Employee $employee, Carbon $bulan): int
    {
        return max(0, $this->jatah() - $this->terpakai($employee, $bulan)->count());
    }

    /**
     * Jadikan satu tanggal sebagai hari libur pilihan.
     *
     * Melempar RuntimeException dengan pesan yang bisa dibaca karyawan — bukan
     * kode error — karena pesan inilah yang muncul di layarnya.
     */
    public function pilih(Employee $employee, Carbon $tanggal): RosterAssignment
    {
        $tanggal = $tanggal->copy()->startOfDay();

        if (! $this->berlakuUntuk($employee)) {
            throw new RuntimeException('Jatah libur pilihan sendiri tidak berlaku untuk posisi Anda.');
        }

        if ($tanggal->lessThan(today())) {
            throw new RuntimeException('Tanggal yang sudah lewat tidak bisa dijadikan libur.');
        }

        $this->lockGuard->ensureUnlocked($tanggal);

        $sisa = $this->sisa($employee, $tanggal);

        if ($sisa < 1) {
            throw new RuntimeException(sprintf(
                'Jatah libur bulan %s sudah habis (%d hari terpakai).',
                $tanggal->translatedFormat('F Y'),
                $this->jatah(),
            ));
        }

        $baris = RosterAssignment::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $tanggal)
            ->get();

        if ($baris->isEmpty()) {
            throw new RuntimeException(
                'Belum ada jadwal untuk '.$tanggal->translatedFormat('d M Y').', jadi belum ada yang bisa dijadikan libur.'
            );
        }

        // Cuti yang sudah disahkan bukan hari kerja, dan menimpanya lewat jalur
        // ini akan diam-diam memakai jatah untuk hari yang memang sudah libur.
        if ($baris->contains(fn (RosterAssignment $b) => $b->status === AssignmentStatus::Leave)) {
            throw new RuntimeException('Tanggal itu sudah tercatat cuti/izin yang disetujui.');
        }

        if ($baris->every(fn (RosterAssignment $b) => $b->shift_id === null)) {
            throw new RuntimeException('Tanggal itu memang sudah libur.');
        }

        return $this->roster->assign(
            $this->roster->findOrCreate((int) $tanggal->year, (int) $tanggal->month),
            $employee,
            $tanggal,
            null,
            null,
            config('attendance.libur_pilihan.source', 'pilihan'),
        );
    }

    /**
     * Tanggal kerja yang masih bisa dipilih jadi libur, mulai hari ini.
     *
     * @return Collection<int, RosterAssignment>
     */
    public function kandidat(Employee $employee, int $hariKeDepan = 60): Collection
    {
        return RosterAssignment::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [
                today()->startOfDay(),
                today()->copy()->addDays($hariKeDepan)->endOfDay(),
            ])
            ->working()
            ->orderBy('work_date')
            ->get();
    }
}
