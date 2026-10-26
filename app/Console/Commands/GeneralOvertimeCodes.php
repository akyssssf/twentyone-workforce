<?php

namespace App\Console\Commands;

use App\Enums\OvertimeOccasion;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Requests\RequestService;
use App\Support\DateInput;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Kode lembur untuk hari kerja bersama (General Cleaning): satu kode per orang,
 * dibagikan manajer sendiri.
 *
 * Kodenya cuma bukti "orang ini ikut" — jamnya dari scan (lembur:umum), karena
 * lembur biasa menyambung SETELAH jam pulang shift dan menolak yang libur.
 * Tidak ada WhatsApp otomatis ke siapa pun; kodenya hanya tampil di terminal.
 */
class GeneralOvertimeCodes extends Command
{
    protected $signature = 'lembur:kode-umum
                            {tanggal : Tanggal acara (YYYY-MM-DD)}
                            {--pengali=2 : Pengali tarif lembur biasa}
                            {--pin=* : Hanya PIN ini (boleh diulang)}
                            {--abaikan=* : Lewati PIN ini (boleh diulang)}
                            {--alasan=General cleaning : Tertulis di catatan}';

    protected $description = 'Buat kode lembur untuk semua karyawan pada hari kerja bersama (General Cleaning)';

    public function handle(RequestService $service): int
    {
        $tanggal = DateInput::parseOrFail((string) $this->argument('tanggal'), 'tanggal');
        $pengali = (float) $this->option('pengali');

        if ($pengali < 1 || $pengali > 5) {
            $this->error('--pengali harus antara 1 dan 5.');

            return self::FAILURE;
        }

        $admin = User::where('role', 'admin')->first() ?? User::first();

        if ($admin === null) {
            $this->error('Tidak ada akun pengguna untuk dicatat sebagai penanggung jawab.');

            return self::FAILURE;
        }

        Auth::login($admin);

        $pins = array_map('strval', (array) $this->option('pin'));
        $abaikan = array_map('strval', (array) $this->option('abaikan'));

        $karyawan = Employee::query()->tracked()->employed()->orderBy('name')->get()
            ->when($pins !== [], fn ($c) => $c->filter(fn ($e) => in_array((string) $e->pin_device, $pins, true)))
            ->reject(fn ($e) => in_array((string) $e->pin_device, $abaikan, true));

        $baris = [];

        foreach ($karyawan as $e) {
            $ada = OvertimeRequest::query()
                ->whereDate('work_date', $tanggal)
                ->where('occasion', OvertimeOccasion::GeneralCleaning->value)
                ->whereHas('request', fn ($q) => $q->where('employee_id', $e->id)->where('status', '!=', 'cancelled'))
                ->first();

            try {
                $overtime = $ada ?? $service->submitGeneralOvertime($e, $tanggal, $pengali, (string) $this->option('alasan'))->overtime;
            } catch (RuntimeException $ex) {
                $this->error($e->name.': '.$ex->getMessage());

                return self::FAILURE;
            }

            $baris[] = [$e->pin_device, $e->name, $overtime->secret_code, $ada ? 'sudah ada' : 'baru'];
        }

        $this->newLine();
        $this->line("Kode lembur {$tanggal->translatedFormat('l, d F Y')} — tarif ×{$pengali}");
        $this->table(['PIN', 'Nama', 'KODE', ''], $baris);
        $this->line('Karyawan memasukkan kodenya di menu Lembur pada portal. Kode hanya berlaku di sekitar tanggal acara (±1 hari).');
        $this->line('Tidak ada pesan WhatsApp yang dikirim; bagikan sendiri. Jam dihitung dari scan: php artisan lembur:umum '.$tanggal->toDateString());

        return self::SUCCESS;
    }
}
