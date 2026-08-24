<?php

namespace App\Console\Commands;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\AttendanceAdjustment;
use App\Models\Employee;
use App\Models\User;
use App\Support\DateInput;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * Tandai status kehadiran seseorang pada satu tanggal — sakit, izin, cuti,
 * hadir, libur, atau alpha.
 *
 * Ditulis sebagai koreksi di attendance_adjustments, BUKAN dengan mengubah
 * baris attendances langsung. Alasannya sama seperti koreksi lain: attendances
 * murni turunan, dan cron yang menghitung ulang tiap 15 menit akan menghapus
 * perubahan langsung tanpa ada yang sadar. Koreksi hidup sebagai INPUT.
 *
 * Ini jalur yang sama persis dengan yang dipakai approval cuti (RequestService
 * membuat set_status untuk tiap tanggalnya), jadi bukan pintu belakang —
 * cuma pintu depan tanpa formulir, untuk kejadian yang sudah telanjur lewat.
 *
 * Alasan wajib: mengubah Alpha jadi Sakit menyangkut uang, dan yang tidak bisa
 * dijelaskan enam bulan kemudian sama saja dengan angka yang berubah sendiri.
 */
class SetAttendanceStatus extends Command
{
    protected $signature = 'attendance:tandai
                            {pin : PIN karyawan di mesin}
                            {tanggal : Tanggal (YYYY-MM-DD)}
                            {status : hadir, alpha, izin, sakit, cuti, atau libur}
                            {--alasan= : Kenapa ditandai begitu}
                            {--batal : Batalkan penandaan yang sudah ada}';

    protected $description = 'Tandai status kehadiran seseorang pada satu tanggal';

    public function handle(): int
    {
        $employee = Employee::where('pin_device', (string) $this->argument('pin'))->first();

        if ($employee === null) {
            $this->error("Tidak ada karyawan dengan PIN {$this->argument('pin')}.");

            return self::FAILURE;
        }

        $tanggal = DateInput::parseOrFail((string) $this->argument('tanggal'), 'tanggal');

        $status = AttendanceStatus::tryFrom(strtolower(trim((string) $this->argument('status'))));

        if ($status === null) {
            $this->error("Status '{$this->argument('status')}' tidak dikenal.");
            $this->line('Yang tersedia: '.collect(AttendanceStatus::cases())->pluck('value')->implode(', '));

            return self::FAILURE;
        }

        $this->line("Karyawan: {$employee->name}  Tanggal: {$tanggal->toDateString()}");
        $this->line('Sebelum : '.$this->ringkas($employee, $tanggal));

        // shift_key 0 = berlaku untuk shift mana pun di tanggal itu. Penandaan
        // status memang keputusan tingkat-hari, bukan per shift.
        $koreksi = AttendanceAdjustment::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $tanggal)
            ->where('type', 'set_status')
            ->whereNull('reverted_by_id')
            ->first();

        if ($this->option('batal')) {
            if ($koreksi === null) {
                $this->error('Tidak ada penandaan status yang aktif pada tanggal itu.');

                return self::FAILURE;
            }

            // Ditandai batal, bukan dihapus — tabel ini append-only supaya jejak
            // keputusannya tidak hilang.
            $koreksi->update(['reverted_by_id' => $koreksi->id]);
            $this->info('Penandaan dibatalkan, statusnya dihitung ulang dari scan.');
        } else {
            $alasan = trim((string) $this->option('alasan'));

            if ($alasan === '') {
                $this->error('Isi --alasan. Mengubah status menyangkut uang, jadi harus bisa dijelaskan nanti.');

                return self::FAILURE;
            }

            if ($koreksi !== null) {
                // Penandaan lama dibatalkan dulu, bukan dibiarkan menumpuk:
                // dua set_status aktif di tanggal yang sama membuat hasilnya
                // bergantung urutan baris, dan itu tidak bisa ditebak siapa pun.
                $koreksi->update(['reverted_by_id' => $koreksi->id]);
                $this->warn("Penandaan sebelumnya ({$koreksi->value_status}) dibatalkan lebih dulu.");
            }

            AttendanceAdjustment::create([
                'employee_id' => $employee->id,
                'work_date' => $tanggal,
                'shift_key' => 0,
                'type' => 'set_status',
                'value_status' => $status->value,
                'reason' => $alasan,
                'approved_by' => (User::where('role', 'admin')->first() ?? User::first())?->id,
                'approved_at' => now(),
            ]);

            $this->info("{$employee->name} pada {$tanggal->toDateString()} ditandai {$status->label()}.");
        }

        Artisan::call('attendance:compute', [
            '--from' => $tanggal->toDateString(),
            '--to' => $tanggal->toDateString(),
        ]);

        $this->line('Sesudah : '.$this->ringkas($employee, $tanggal));

        return self::SUCCESS;
    }

    protected function ringkas(Employee $employee, Carbon $tanggal): string
    {
        $baris = Attendance::with('shift')
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $tanggal)
            ->get();

        if ($baris->isEmpty()) {
            return '(belum ada rekap absensi untuk tanggal ini)';
        }

        return $baris->map(fn (Attendance $a) => sprintf('%s — %s, masuk %s',
            $a->shift?->name ?? '-',
            $a->status->label(),
            $a->check_in_at?->format('H:i') ?? '—',
        ))->implode(' | ');
    }
}
