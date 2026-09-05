<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendanceAdjustment;
use App\Models\Employee;
use App\Models\User;
use App\Support\DateInput;
use App\Support\Durasi;
use App\Support\OperationalDate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * Maafkan keterlambatan satu karyawan pada satu tanggal.
 *
 * Ditulis sebagai koreksi di attendance_adjustments, BUKAN dengan mengubah
 * langsung baris attendances. Alasannya sama seperti koreksi lain: tabel
 * attendances murni turunan, dan cron attendance:compute yang jalan tiap 15
 * menit akan menghapus perubahan langsung tanpa ada yang sadar. Koreksi hidup
 * sebagai INPUT, jadi ikut diterapkan ulang setiap kali dihitung.
 *
 * Alasan wajib diisi: memaafkan telat itu keputusan yang menyangkut uang, dan
 * yang tidak bisa dijelaskan enam bulan kemudian sama saja dengan angka yang
 * berubah sendiri.
 */
class WaiveLate extends Command
{
    protected $signature = 'attendance:waive-late
                            {pin : PIN karyawan di mesin}
                            {tanggal? : Tanggal (YYYY-MM-DD), kosong berarti hari ini}
                            {--alasan= : Kenapa dimaafkan}
                            {--pulang-cepat : Maafkan pulang cepatnya, bukan telatnya}
                            {--keduanya : Maafkan telat DAN pulang cepat}
                            {--batal : Batalkan koreksi yang sudah ada, bukan membuat baru}';

    protected $description = 'Maafkan keterlambatan atau pulang cepat seorang karyawan pada satu tanggal';

    public function handle(): int
    {
        $employee = Employee::where('pin_device', (string) $this->argument('pin'))->first();

        if ($employee === null) {
            $this->error("Tidak ada karyawan dengan PIN {$this->argument('pin')}.");

            return self::FAILURE;
        }

        $tanggal = $this->argument('tanggal')
            ? DateInput::parseOrFail((string) $this->argument('tanggal'), 'tanggal')
            : OperationalDate::today();

        $jenis = $this->jenisYangDimaafkan();

        $this->line("Karyawan: {$employee->name}  Tanggal: {$tanggal->toDateString()}");
        $this->line('Sebelum : '.$this->ringkas($employee, $tanggal));

        if ($this->option('batal')) {
            $dibatalkan = AttendanceAdjustment::query()
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $tanggal)
                ->whereIn('type', $jenis)
                ->whereNull('reverted_by_id')
                ->get();

            if ($dibatalkan->isEmpty()) {
                $this->error('Tidak ada koreksi yang aktif pada tanggal itu.');

                return self::FAILURE;
            }

            foreach ($dibatalkan as $koreksi) {
                // Ditandai batal, bukan dihapus — tabel ini append-only supaya
                // jejak keputusannya tidak hilang.
                $koreksi->update(['reverted_by_id' => $koreksi->id]);
            }

            $this->info($dibatalkan->count().' koreksi dibatalkan, dihitung lagi seperti biasa.');
        } else {
            $alasan = (string) $this->option('alasan');

            if (trim($alasan) === '') {
                $this->error('Isi --alasan. Memaafkan telat atau pulang cepat menyangkut uang, jadi harus bisa dijelaskan nanti.');

                return self::FAILURE;
            }

            foreach ($jenis as $tipe) {
                $sudah = AttendanceAdjustment::query()
                    ->where('employee_id', $employee->id)
                    ->whereDate('work_date', $tanggal)
                    ->where('type', $tipe)
                    ->whereNull('reverted_by_id')
                    ->exists();

                if ($sudah) {
                    $this->warn($this->sebutan($tipe).' sudah pernah dimaafkan untuk tanggal ini — tidak dibuat lagi.');

                    continue;
                }

                AttendanceAdjustment::create([
                    'employee_id' => $employee->id,
                    'work_date' => $tanggal,
                    'shift_key' => 0,
                    'type' => $tipe,
                    'reason' => $alasan,
                    'approved_by' => (User::where('role', 'admin')->first() ?? User::first())?->id,
                    'approved_at' => now(),
                ]);

                $this->info(sprintf('%s %s pada %s dimaafkan.',
                    $this->sebutan($tipe), $employee->name, $tanggal->toDateString()));
            }
        }

        Artisan::call('attendance:compute', [
            '--from' => $tanggal->toDateString(),
            '--to' => $tanggal->toDateString(),
        ]);

        $this->line('Sesudah : '.$this->ringkas($employee, $tanggal));

        return self::SUCCESS;
    }

    /**
     * Jenis koreksi yang diminta. Bawaannya telat saja, supaya perilaku lama
     * perintah ini tidak berubah bagi yang sudah terbiasa memakainya.
     *
     * @return list<string>
     */
    protected function jenisYangDimaafkan(): array
    {
        if ($this->option('keduanya')) {
            return ['waive_late', 'waive_early_leave'];
        }

        return $this->option('pulang-cepat') ? ['waive_early_leave'] : ['waive_late'];
    }

    protected function sebutan(string $tipe): string
    {
        return $tipe === 'waive_early_leave' ? 'Pulang cepat' : 'Telat';
    }

    protected function ringkas(Employee $employee, Carbon $tanggal): string
    {
        $a = Attendance::with('shift')
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $tanggal)
            ->orderByDesc('late_minutes')
            ->orderByDesc('early_leave_minutes')
            ->first();

        if ($a === null) {
            return '(belum ada rekap absensi untuk tanggal ini)';
        }

        return sprintf('%s, masuk %s, pulang %s, telat %s, pulang cepat %s',
            $a->shift?->name ?? '-',
            $a->check_in_at?->format('H:i') ?? '-',
            $a->check_out_at?->format('H:i') ?? '-',
            Durasi::menit($a->late_minutes),
            Durasi::menit($a->early_leave_minutes));
    }
}
