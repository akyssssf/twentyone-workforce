<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Shift;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Impor karyawan massal dari CSV, buat pengisian awal.
 *
 * Idempoten berdasarkan pin_device: menjalankan berkas yang sama dua kali
 * memperbarui, bukan menggandakan. Jadi berkasnya bisa diperbaiki lalu
 * diimpor ulang tanpa perlu membersihkan apa pun dulu.
 */
class ImportEmployees extends Command
{
    protected $signature = 'employee:import
                            {file : Berkas CSV}
                            {--dry-run : Tampilkan hasilnya tanpa menyimpan}';

    protected $description = 'Impor karyawan massal dari CSV';

    /** Kolom wajib ada di baris judul. */
    protected const WAJIB = ['pin_device', 'name'];

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Berkas tidak ditemukan atau tidak terbaca: {$path}");
            $this->line('Contoh isi berkas:');
            $this->line('  <comment>pin_device,name,phone,shift,base_salary,joined_at</comment>');
            $this->line('  <comment>1,Budi,081234567890,Shift 1,3000000,2026-08-01</comment>');

            return self::FAILURE;
        }

        $rows = $this->bacaCsv($path);

        if ($rows === null) {
            return self::FAILURE;
        }

        if ($rows === []) {
            $this->warn('Berkas tidak berisi baris data.');

            return self::SUCCESS;
        }

        $shifts = Shift::all();
        $dryRun = (bool) $this->option('dry-run');

        $baru = 0;
        $diperbarui = 0;
        $gagal = 0;
        $ringkasan = [];

        foreach ($rows as $nomor => $row) {
            $galat = $this->validasi($row, $shifts);

            if ($galat !== null) {
                $this->error("Baris {$nomor}: {$galat}");
                $gagal++;

                continue;
            }

            $shift = $this->cariShift($row['shift'] ?? null, $shifts);
            $sudahAda = Employee::where('pin_device', $row['pin_device'])->exists();

            if (! $dryRun) {
                $employee = Employee::updateOrCreate(
                    ['pin_device' => $row['pin_device']],
                    [
                        'branch_id' => \App\Models\Branch::current()->id,
                        'name' => $row['name'],
                        // Diseragamkan oleh mutator di model Employee.
                        'phone' => $row['phone'] ?? null,
                        'default_shift_id' => $shift?->id,
                        'employment_status' => 'active',
                        'is_active' => $this->keBoolean($row['is_active'] ?? '1'),
                        'joined_at' => $this->keTanggal($row['joined_at'] ?? null),
                    ],
                );

                $mulai = ($employee->joined_at ?? now())->toDateString();

                // PIN jadi pemetaan berperiode, bukan sekadar kolom.
                $employee->devices()->firstOrCreate(
                    ['pin' => $row['pin_device'], 'valid_to' => null],
                    ['cloud_id' => config('fingerspot.cloud_id') ?: 'default', 'valid_from' => $mulai],
                );

                // Gaji masuk riwayat gaji supaya kenaikan gaji nanti tidak
                // mengubah slip gaji bulan-bulan sebelumnya.
                $gaji = (int) preg_replace('/\D+/', '', (string) ($row['base_salary'] ?? 0));

                if ($gaji > 0 && ($komponen = \App\Models\SalaryComponent::where('code', 'gaji_pokok')->first())) {
                    $employee->salaries()->updateOrCreate(
                        ['salary_component_id' => $komponen->id, 'effective_from' => $mulai],
                        ['amount' => $gaji],
                    );
                }
            }

            $sudahAda ? $diperbarui++ : $baru++;

            $ringkasan[] = [
                $row['pin_device'],
                $row['name'],
                $shift?->name ?? '-',
                $sudahAda ? 'diperbarui' : 'baru',
            ];
        }

        if ($ringkasan !== []) {
            $this->table(['PIN', 'Nama', 'Shift', 'Tindakan'], $ringkasan);
        }

        $this->info(sprintf('%d baru, %d diperbarui, %d gagal.', $baru, $diperbarui, $gagal));

        if ($dryRun) {
            $this->newLine();
            $this->warn('Mode --dry-run: tidak ada yang disimpan.');
        } elseif ($baru + $diperbarui > 0) {
            $this->newLine();
            $this->line('Jalankan <comment>php artisan attendance:compute</comment> untuk memasukkan scan lama ke rekap.');
        }

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int, array<string, string>>|null
     */
    protected function bacaCsv(string $path): ?array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            $this->error('Berkas gagal dibuka.');

            return null;
        }

        $judul = fgetcsv($handle);

        if ($judul === false) {
            fclose($handle);
            $this->error('Berkas kosong.');

            return null;
        }

        // Buang BOM yang biasa ditinggalkan Excel di kolom pertama.
        $judul[0] = preg_replace('/^\x{FEFF}/u', '', (string) $judul[0]);
        $judul = array_map(fn ($k) => strtolower(trim((string) $k)), $judul);

        $hilang = array_diff(self::WAJIB, $judul);

        if ($hilang !== []) {
            fclose($handle);
            $this->error('Kolom wajib tidak ada: '.implode(', ', $hilang));
            $this->line('Baris judul harus memuat minimal: <comment>'.implode(',', self::WAJIB).'</comment>');

            return null;
        }

        $rows = [];
        $nomor = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $nomor++;

            // Lewati baris kosong yang sering tersisa di akhir berkas.
            if ($data === [null] || implode('', array_map('strval', $data)) === '') {
                continue;
            }

            $row = array_combine(
                $judul,
                array_map(fn ($v) => trim((string) $v), array_pad(array_slice($data, 0, count($judul)), count($judul), '')),
            );

            $rows[$nomor] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<string, string>  $row
     * @param  \Illuminate\Support\Collection<int, Shift>  $shifts
     */
    protected function validasi(array $row, $shifts): ?string
    {
        if (($row['pin_device'] ?? '') === '') {
            return 'pin_device kosong.';
        }

        if (($row['name'] ?? '') === '') {
            return 'name kosong.';
        }

        if (($row['shift'] ?? '') !== '' && $this->cariShift($row['shift'], $shifts) === null) {
            return "shift \"{$row['shift']}\" tidak ada. Pilihan: ".$shifts->pluck('name')->implode(', ');
        }

        if (($row['joined_at'] ?? '') !== '' && $this->keTanggal($row['joined_at']) === null) {
            return "joined_at \"{$row['joined_at']}\" bukan tanggal YYYY-MM-DD.";
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Shift>  $shifts
     */
    protected function cariShift(?string $nilai, $shifts): ?Shift
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        return $shifts->first(fn (Shift $s) => strcasecmp($s->name, $nilai) === 0)
            ?? (ctype_digit($nilai) ? $shifts->firstWhere('id', (int) $nilai) : null);
    }

    protected function keTanggal(?string $nilai): ?Carbon
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        try {
            $tanggal = Carbon::createFromFormat('Y-m-d', $nilai);
        } catch (\Exception) {
            return null;
        }

        return $tanggal->format('Y-m-d') === $nilai ? $tanggal->startOfDay() : null;
    }

    protected function keBoolean(string $nilai): bool
    {
        return ! in_array(strtolower(trim($nilai)), ['0', 'no', 'tidak', 'false', 'nonaktif'], true);
    }
}
