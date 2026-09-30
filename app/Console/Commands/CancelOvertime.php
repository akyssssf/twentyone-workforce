<?php

namespace App\Console\Commands;

use App\Enums\RequestStatus;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\Request;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\DateInput;
use App\Support\Durasi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Batalkan lembur yang keliru ditugaskan.
 *
 * `lembur:tugaskan` oleh manajer langsung SAH, dan pengajuan yang sudah sah
 * tidak bisa dibatalkan lewat jalur pengajuan biasa. Tanpa perintah ini,
 * lembur yang salah orang atau salah tanggal tetap terbayar penuh: catatan
 * realisasinya sudah "confirmed", dan payroll menjumlahkan apa pun yang
 * confirmed — tidak peduli rosternya sudah dikoreksi.
 *
 * Catatannya TIDAK dihapus. Statusnya jadi "cancelled" dengan menit dibayar
 * nol dan alasan tertulis, supaya pertanyaan "dulu kenapa lembur ini hilang"
 * tetap bisa dijawab.
 */
class CancelOvertime extends Command
{
    protected $signature = 'lembur:batal
                            {pin : PIN karyawan}
                            {tanggal : Tanggal lembur (YYYY-MM-DD)}
                            {--alasan= : Kenapa dibatalkan (wajib)}';

    protected $description = 'Batalkan lembur yang keliru ditugaskan, supaya tidak ikut terbayar';

    public function handle(): int
    {
        $employee = Employee::where('pin_device', (string) $this->argument('pin'))->first();

        if ($employee === null) {
            $this->error("Tidak ada karyawan dengan PIN {$this->argument('pin')}.");

            return self::FAILURE;
        }

        $alasan = trim((string) $this->option('alasan'));

        if ($alasan === '') {
            $this->error('Isi --alasan. Lembur yang hilang tanpa penjelasan adalah uang yang tidak bisa dipertanggungjawabkan.');

            return self::FAILURE;
        }

        $tanggal = DateInput::parseOrFail((string) $this->argument('tanggal'), 'tanggal');

        $records = OvertimeRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $tanggal)
            ->where('status', '!=', 'cancelled')
            ->get();

        if ($records->isEmpty()) {
            $this->error("{$employee->name} tidak punya lembur aktif pada {$tanggal->toDateString()}.");

            return self::FAILURE;
        }

        // Konfirmasi manual dicatat atas nama akun: terminal tidak punya sesi.
        $admin = User::where('role', 'admin')->first() ?? User::first();

        if ($admin !== null) {
            Auth::login($admin);
        }

        DB::transaction(function () use ($records, $admin, $alasan) {
            foreach ($records as $record) {
                $sebelum = $record->only(['status', 'payable_minutes', 'actual_minutes']);

                // confirmed_by diisi supaya perhitungan otomatis (yang cuma
                // menyentuh catatan yang belum disahkan manusia) tidak
                // menghidupkannya lagi lima belas menit kemudian.
                $record->update([
                    'status' => 'cancelled',
                    'payable_minutes' => 0,
                    'confirmed_by' => $admin?->id,
                    'confirmed_at' => now(),
                    'note' => 'Dibatalkan: '.$alasan,
                ]);

                if ($record->overtime_request_id) {
                    Request::query()
                        ->whereKey($record->overtime_request_id)
                        ->update([
                            'status' => RequestStatus::Cancelled->value,
                            'cancelled_at' => now(),
                        ]);
                }

                AuditLogger::record('overtime.cancelled', $record, $sebelum, [
                    'status' => 'cancelled',
                    'reason' => $alasan,
                ]);

                $this->line(sprintf('  Dibatalkan: lembur %s (tercatat %s) — dibayar jadi 0',
                    $record->work_date->toDateString(),
                    Durasi::menit((int) ($sebelum['payable_minutes'] ?? 0))));
            }
        });

        Artisan::call('attendance:compute', [
            '--from' => $tanggal->toDateString(),
            '--to' => $tanggal->toDateString(),
        ]);

        $this->info("Lembur {$employee->name} pada {$tanggal->toDateString()} dibatalkan, absensinya dihitung ulang.");
        $this->line('Kalau payroll periodenya sudah dihitung, hitung ulang supaya bonusnya hilang dari slip.');

        return self::SUCCESS;
    }
}
