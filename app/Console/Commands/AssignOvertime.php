<?php

namespace App\Console\Commands;

use App\Enums\OvertimeOccasion;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\User;
use App\Services\Requests\RequestService;
use App\Support\DateInput;
use App\Support\Durasi;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Tugaskan lembur untuk satu orang pada satu tanggal.
 *
 * Jamnya TIDAK diketik. Lembur di kafe ini selalu menyambung shift orangnya —
 * mulai tepat saat shift terjadwalnya selesai, sampai kafe tutup — jadi
 * jamnya diturunkan dari roster, bukan dari ingatan admin. Yang perlu
 * diputuskan manusia cuma siapa, tanggal berapa, dan untuk keperluan apa.
 *
 * Lamanya yang dibayar dihitung belakangan dari scan terakhir; kalau tidak ada
 * scan pulang, dihitung penuh sampai jam tutup. Itu pilihan sadar supaya yang
 * lembur tidak dirugikan karena lupa menempel jari.
 *
 * Memakai jalur yang sama persis dengan formulir Lembur di panel admin
 * (`submitOvertime` dengan initiatedBy = manager), jadi bukan pintu belakang:
 * penugasan langsung oleh manajer memang sah sejak dibuat, dan tetap tercatat
 * di audit log.
 */
class AssignOvertime extends Command
{
    protected $signature = 'lembur:tugaskan
                            {pin : PIN karyawan yang lembur}
                            {tanggal : Tanggal (YYYY-MM-DD)}
                            {--keperluan=acara : pengganti, live_music, nobar, atau acara}
                            {--pengganti= : PIN orang yang digantikan, wajib kalau keperluannya pengganti}
                            {--alasan= : Kenapa lembur ini ditugaskan}
                            {--aktifkan : Sahkan langsung tanpa kode, untuk lembur yang sudah telanjur lewat}';

    protected $description = 'Tugaskan lembur seorang karyawan pada satu tanggal';

    public function handle(RequestService $service): int
    {
        $employee = Employee::where('pin_device', (string) $this->argument('pin'))->first();

        if ($employee === null) {
            $this->error("Tidak ada karyawan dengan PIN {$this->argument('pin')}.");

            return self::FAILURE;
        }

        $tanggal = DateInput::parseOrFail((string) $this->argument('tanggal'), 'tanggal');

        $keperluan = OvertimeOccasion::tryFrom(strtolower(trim((string) $this->option('keperluan'))));

        if ($keperluan === null) {
            $this->error("Keperluan '{$this->option('keperluan')}' tidak dikenal.");
            $this->line('Yang tersedia: '.collect(OvertimeOccasion::cases())->pluck('value')->implode(', '));

            return self::FAILURE;
        }

        $alasan = trim((string) $this->option('alasan'));

        if ($alasan === '') {
            $this->error('Isi --alasan. Lembur dibayar, jadi harus bisa dijelaskan nanti.');

            return self::FAILURE;
        }

        $pengganti = null;

        if ($keperluan->butuhPengganti()) {
            $pinPengganti = (string) $this->option('pengganti');

            // Lembur "pengganti" berarti ada posisi orang lain yang ditutup.
            // Tanpa menyebut siapa, catatannya tidak bisa dipakai menjelaskan
            // apa pun enam bulan kemudian.
            if ($pinPengganti === '') {
                $this->error('Keperluan "pengganti" wajib menyebut siapa yang digantikan lewat --pengganti=PIN.');

                return self::FAILURE;
            }

            $pengganti = Employee::where('pin_device', $pinPengganti)->first();

            if ($pengganti === null) {
                $this->error("Tidak ada karyawan dengan PIN {$pinPengganti}.");

                return self::FAILURE;
            }
        }

        // submitOvertime mencatat siapa yang memutuskan lewat auth(). Di
        // terminal tidak ada sesi, jadi akun admin dipasang eksplisit —
        // penugasan lembur tanpa penanggung jawab adalah catatan yang tidak
        // bisa dipertanggungjawabkan.
        $admin = User::where('role', 'admin')->first() ?? User::first();

        if ($admin === null) {
            $this->error('Tidak ada satu pun akun pengguna untuk dicatat sebagai penanggung jawab.');

            return self::FAILURE;
        }

        Auth::login($admin);

        $this->line("Karyawan: {$employee->name}  Tanggal: {$tanggal->toDateString()}");
        $this->line('Sebelum : '.$this->ringkas($employee, $tanggal));

        try {
            $request = $service->submitOvertime($employee, [
                'work_date' => $tanggal->toDateString(),
                'occasion' => $keperluan,
                'substitute_employee_id' => $pengganti?->id,
                'reason' => $alasan,
            ], 'manager');
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $lembur = $request->fresh()->overtime;

        $this->newLine();
        $this->info("Lembur ditugaskan: {$request->code} ({$keperluan->label()}).");
        $this->line(sprintf('  Rencana : %s–%s (%s)',
            substr((string) $lembur->planned_start, 0, 5),
            substr((string) $lembur->planned_end, 0, 5),
            Durasi::menit((int) $lembur->planned_minutes)));

        if ($pengganti !== null) {
            $this->line("  Menggantikan: {$pengganti->name}");
        }

        $this->aktivasi($employee, $request, $lembur, $admin);

        Artisan::call('attendance:compute', [
            '--from' => $tanggal->toDateString(),
            '--to' => $tanggal->toDateString(),
        ]);

        $this->newLine();
        $this->line('Sesudah : '.$this->ringkas($employee, $tanggal));
        $this->newLine();
        $this->line('Lamanya yang tercatat dihitung dari jam pulang terjadwal sampai scan terakhir.');
        $this->line('Tanpa scan pulang, dihitung penuh sampai jam tutup kafe.');

        return self::SUCCESS;
    }

    /**
     * Lembur baru terhitung setelah karyawannya MENGAKTIFKAN kodenya — itu
     * satu-satunya bukti bahwa orang yang ditunjuk benar-benar mengerjakannya.
     * Tanpa aktivasi, durasinya tidak pernah dihitung dan tidak ada yang
     * terbayar, walaupun penugasannya sudah sah.
     *
     * Kodenya cuma berlaku di sekitar tanggal lemburnya (±1 hari), jadi untuk
     * lembur yang sudah telanjur lewat jalur itu tertutup. --aktifkan ada untuk
     * kasus itu: manajer yang menyatakan lemburnya memang terjadi, dan
     * pernyataan itu tercatat atas namanya.
     */
    protected function aktivasi(Employee $employee, $request, $lembur, User $admin): void
    {
        $record = OvertimeRecord::query()
            ->where('overtime_request_id', $request->id)
            ->where('employee_id', $employee->id)
            ->first();

        if ($record === null) {
            $this->warn('Catatan realisasi lembur belum terbentuk — durasinya belum bisa dihitung.');

            return;
        }

        if (! $this->option('aktifkan')) {
            $this->newLine();
            $this->warn('BELUM dihitung. Lembur baru terhitung setelah diaktifkan karyawannya.');
            $this->line("  Kode untuk {$employee->name}: <comment>{$lembur->secret_code}</comment>");
            $this->line('  Kode ini hanya berlaku di sekitar tanggal lemburnya (±1 hari).');
            $this->line('  Untuk lembur yang sudah lewat, ulangi perintah ini dengan <info>--aktifkan</info>.');

            return;
        }

        $record->update([
            'activated_at' => now(),
            'activated_by' => $admin->id,
        ]);

        $this->newLine();
        $this->info('Disahkan langsung oleh admin, tanpa kode.');
        $this->line('  Tercatat atas nama '.$admin->name.'; durasinya dihitung dari scan terakhir.');
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

        return $baris->map(fn (Attendance $a) => sprintf('%s — masuk %s, pulang %s, kerja %s, lembur %s',
            $a->shift?->name ?? '-',
            $a->check_in_at?->format('H:i') ?? '—',
            $a->check_out_at?->format('H:i') ?? '—',
            Durasi::menit((int) $a->work_minutes),
            Durasi::menit((int) $a->overtime_minutes),
        ))->implode(' | ');
    }
}
