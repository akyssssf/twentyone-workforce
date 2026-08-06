<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Shift;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Pendaftaran karyawan satu per satu.
 *
 * Bisa dipakai interaktif maupun sekali jalan lewat opsi, supaya cocok untuk
 * mendaftar sambil berdiri di depan mesin saat tes hardware.
 */
class AddEmployee extends Command
{
    protected $signature = 'employee:add
                            {--pin= : PIN di mesin Fingerspot}
                            {--name= : Nama karyawan}
                            {--phone= : Nomor HP}
                            {--shift= : Nama atau id shift}
                            {--salary= : Gaji pokok bulanan}
                            {--joined= : Tanggal bergabung (YYYY-MM-DD)}';

    protected $description = 'Daftarkan satu karyawan dan petakan ke PIN mesin';

    public function handle(): int
    {
        if (Shift::active()->doesntExist()) {
            $this->error('Belum ada shift aktif. Jalankan dulu: php artisan db:seed --class=ShiftSeeder');

            return self::FAILURE;
        }

        // Kalau PIN dan nama sudah diberikan lewat opsi, anggap ini dipanggil
        // dari skrip: jangan pernah berhenti menunggu jawaban, field opsional
        // yang tidak diisi cukup memakai nilai bawaan.
        $noninteraktif = $this->option('pin') !== null && $this->option('name') !== null;

        if (! $noninteraktif) {
            $this->tampilkanPinAsing();
        }

        $pin = $this->option('pin') ?: $this->ask('PIN di mesin');

        if (! $pin = $this->validasiPin((string) $pin)) {
            return self::FAILURE;
        }

        $name = $this->option('name') ?: $this->ask('Nama karyawan');

        if (trim((string) $name) === '') {
            $this->error('Nama tidak boleh kosong.');

            return self::FAILURE;
        }

        $shift = $this->pilihShift($noninteraktif);

        if ($shift === null) {
            return self::FAILURE;
        }

        $hariIni = Carbon::today(config('attendance.timezone'))->toDateString();

        $phone = $this->option('phone') ?? ($noninteraktif ? null : $this->ask('Nomor HP (boleh dikosongkan)'));
        $salary = $this->option('salary') ?? ($noninteraktif ? '0' : $this->ask('Gaji pokok bulanan', '0'));
        $joined = $this->option('joined') ?? ($noninteraktif ? $hariIni : $this->ask('Tanggal bergabung (YYYY-MM-DD)', $hariIni));

        try {
            $joinedAt = $joined ? Carbon::createFromFormat('Y-m-d', $joined)->startOfDay() : null;
        } catch (\Exception) {
            $this->error("Tanggal bergabung tidak sah: \"{$joined}\".");

            return self::FAILURE;
        }

        $employee = Employee::create([
            'branch_id' => \App\Models\Branch::current()->id,
            'employee_no' => sprintf('EMP-%03d', Employee::withTrashed()->count() + 1),
            'pin_device' => $pin,
            'name' => trim((string) $name),
            'phone' => $phone ?: null,
            'default_shift_id' => $shift->id,
            'employment_status' => 'active',
            'is_active' => true,
            'joined_at' => $joinedAt,
        ]);

        // PIN mesin disimpan sebagai pemetaan BERPERIODE, bukan sekadar kolom
        // di employees. Kalau PIN ini nanti dipindahkan ke karyawan lain,
        // riwayat absensi yang lama tetap menempel pada orangnya.
        $employee->devices()->create([
            'cloud_id' => config('fingerspot.cloud_id') ?: 'default',
            'pin' => $pin,
            'valid_from' => ($joinedAt ?? now())->toDateString(),
        ]);

        // Gaji pokok masuk riwayat gaji, bukan kolom tunggal, supaya kenaikan
        // gaji nanti tidak mengubah slip gaji bulan-bulan sebelumnya.
        $gaji = (int) preg_replace('/\D+/', '', (string) $salary);

        if ($gaji > 0) {
            $komponen = \App\Models\SalaryComponent::where('code', 'gaji_pokok')->first();

            if ($komponen !== null) {
                $employee->salaries()->create([
                    'salary_component_id' => $komponen->id,
                    'amount' => $gaji,
                    'effective_from' => ($joinedAt ?? now())->toDateString(),
                ]);
            }
        }

        $this->newLine();
        $this->info("Tersimpan: {$employee->name} (PIN {$employee->pin_device}, {$shift->name}).");

        if ($employee->phone !== null) {
            $this->line('   Nomor HP disimpan sebagai <comment>'.$employee->phone.'</comment> agar siap dipakai WhatsApp.');
        }

        // Scan lama dari PIN ini otomatis jadi miliknya begitu dihitung ulang.
        $lama = AttendanceLog::where('pin', $pin)->count();

        if ($lama > 0) {
            $this->newLine();
            $this->line("Ada {$lama} scan lama dengan PIN ini. Jalankan <comment>php artisan attendance:compute</comment>");
            $this->line('untuk memasukkannya ke rekap.');
        }

        return self::SUCCESS;
    }

    /**
     * PIN yang sudah mengirim scan tapi belum punya karyawan.
     *
     * Sangat membantu saat tes hardware: tempelkan jari, jalankan command ini,
     * dan PIN-nya sudah tersaji tanpa perlu dibaca dari layar mesin.
     */
    protected function tampilkanPinAsing(): void
    {
        $terdaftar = Employee::pluck('pin_device')->all();

        $asing = AttendanceLog::query()
            ->when($terdaftar !== [], fn ($q) => $q->whereNotIn('pin', $terdaftar))
            ->distinct()
            ->pluck('pin');

        if ($asing->isNotEmpty()) {
            $this->line('PIN yang sudah mengirim scan tapi belum terdaftar: <comment>'.$asing->implode(', ').'</comment>');
            $this->newLine();
        }
    }

    protected function validasiPin(string $pin): ?string
    {
        $pin = trim($pin);

        if ($pin === '') {
            $this->error('PIN tidak boleh kosong.');

            return null;
        }

        // PIN dibandingkan sebagai string persis seperti kiriman mesin, jadi
        // "07" dan "7" adalah dua orang berbeda. Salah di sini berarti absensi
        // masuk ke nama yang salah.
        if ($sudah = Employee::where('pin_device', $pin)->first()) {
            $this->error("PIN {$pin} sudah dipakai {$sudah->name}.");

            return null;
        }

        return $pin;
    }

    protected function pilihShift(bool $noninteraktif = false): ?Shift
    {
        $shifts = Shift::active()->orderBy('id')->get();

        if ($noninteraktif && ! $this->option('shift')) {
            // Menebak shift diam-diam akan mengubah batas on time orang ini,
            // jadi lebih baik berhenti dan minta disebutkan.
            $this->error('--shift wajib disebutkan. Pilihan: '.$shifts->pluck('name')->implode(', '));

            return null;
        }

        if ($pilihan = $this->option('shift')) {
            $shift = $shifts->firstWhere('name', $pilihan) ?? $shifts->firstWhere('id', (int) $pilihan);

            if ($shift === null) {
                $this->error("Shift \"{$pilihan}\" tidak ditemukan. Pilihan: ".$shifts->pluck('name')->implode(', '));

                return null;
            }

            return $shift;
        }

        $daftar = $shifts->mapWithKeys(fn (Shift $s) => [
            $s->name => "{$s->name} (masuk {$s->start_time}, pulang {$s->end_time})",
        ])->all();

        $dipilih = $this->choice('Shift', array_values($daftar), 0);

        return $shifts->first(fn (Shift $s) => str_starts_with($dipilih, $s->name));
    }
}
