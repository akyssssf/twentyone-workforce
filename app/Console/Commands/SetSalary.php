<?php

namespace App\Console\Commands;

use App\Models\Division;
use App\Models\Employee;
use App\Models\SalaryComponent;
use App\Support\DateInput;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Atur gaji pokok sekaligus per divisi atau per orang, berlaku mulai tanggal
 * tertentu.
 *
 * employee:edit --salary sudah ada, tapi hanya satu orang dan selalu berlaku
 * mulai hari ini. Gaji yang ditetapkan pemilik berlaku surut ke periode gajian
 * yang sedang dihitung, dan payroll membaca gaji pada TANGGAL AKHIR periode —
 * jadi --dari harus bisa mundur ke 21 Agustus, bukan cuma "hari ini".
 *
 * Riwayat tetap dijaga: baris lama ditutup sehari sebelum --dari, baris baru
 * dibuka. Baris dengan tanggal mulai yang sama ditimpa (itu koreksi, bukan
 * riwayat baru). Baris yang mulainya SETELAH --dari membuat perintah berhenti:
 * menimpa masa depan diam-diam bukan hal yang boleh terjadi pada gaji.
 */
class SetSalary extends Command
{
    protected $signature = 'gaji:atur
                            {--divisi=* : Kode/nama divisi UTAMA, mis. barista, chef, kasir, waiter — boleh diulang}
                            {--pin=* : PIN karyawan tertentu — boleh diulang; menang atas --divisi}
                            {--jumlah= : Gaji pokok per bulan, mis. 3000000}
                            {--dari= : Berlaku mulai tanggal (YYYY-MM-DD), bawaan hari ini}
                            {--timpa : Hapus baris gaji yang mulainya SETELAH --dari (ditampilkan dulu)}
                            {--ya : Jangan tanya konfirmasi}';

    protected $description = 'Atur gaji pokok per divisi atau per orang, berlaku mulai tanggal tertentu';

    public function handle(): int
    {
        $jumlah = (int) preg_replace('/\D+/', '', (string) $this->option('jumlah'));

        if ($jumlah <= 0) {
            $this->error('Isi --jumlah, mis. --jumlah=3000000');

            return self::FAILURE;
        }

        $dari = $this->option('dari')
            ? DateInput::parseOrFail((string) $this->option('dari'), 'dari')
            : Carbon::today();

        $komponen = SalaryComponent::where('code', 'gaji_pokok')->first();

        if ($komponen === null) {
            $this->error('Komponen gaji "gaji_pokok" belum ada. Jalankan seeder master data dulu.');

            return self::FAILURE;
        }

        $sasaran = $this->sasaran();

        if ($sasaran === null) {
            return self::FAILURE;
        }

        if ($sasaran->isEmpty()) {
            $this->error('Tidak ada karyawan aktif yang cocok.');

            return self::FAILURE;
        }

        $konflik = [];
        $rows = [];

        foreach ($sasaran as $employee) {
            $sekarang = $employee->baseSalaryOn($dari);

            // Baris Rp 0 di masa depan adalah placeholder dari pendaftaran
            // (employee:add tanpa gaji), bukan riwayat — dibersihkan, bukan
            // dijadikan alasan berhenti.
            $masaDepan = $employee->salaries()
                ->where('salary_component_id', $komponen->id)
                ->whereDate('effective_from', '>', $dari)
                ->where('amount', '>', 0)
                ->orderBy('effective_from')
                ->get();

            if ($masaDepan->isNotEmpty()) {
                $konflik[$employee->name] = $masaDepan->map(fn ($b) => sprintf(
                    'Rp %s mulai %s%s',
                    number_format($b->amount, 0, ',', '.'),
                    $b->effective_from->toDateString(),
                    $b->effective_to ? ' s/d '.$b->effective_to->toDateString() : '',
                ))->implode('; ');
            }

            $rows[] = [
                $employee->pin_device,
                $employee->name,
                $employee->primaryDivision()?->name ?? '-',
                $sekarang > 0 ? 'Rp '.number_format($sekarang, 0, ',', '.') : '-',
                'Rp '.number_format($jumlah, 0, ',', '.'),
            ];
        }

        $this->line("Berlaku mulai {$dari->translatedFormat('d M Y')}:");
        $this->table(['PIN', 'Nama', 'Divisi utama', 'Gaji saat itu', 'Gaji baru'], $rows);

        if ($konflik !== []) {
            foreach ($konflik as $nama => $baris) {
                $this->line("  {$nama}: {$baris}");
            }

            if (! $this->option('timpa')) {
                $this->error('Berhenti: ada baris gaji yang mulainya SETELAH '.$dari->toDateString().' untuk '.implode(', ', array_keys($konflik)).' (lihat di atas).');
                $this->line('Pilih --dari yang lebih baru, atau ulangi dengan --timpa untuk menghapus baris itu. Tidak ada yang diubah.');

                return self::FAILURE;
            }

            $this->warn('--timpa: baris di atas akan DIHAPUS dan diganti gaji baru.');
        }

        if (! $this->option('ya') && ! $this->confirm('Simpan?', false)) {
            $this->line('Batal, tidak ada yang diubah.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($sasaran, $komponen, $jumlah, $dari) {
            foreach ($sasaran as $employee) {
                $employee->salaries()
                    ->where('salary_component_id', $komponen->id)
                    ->whereDate('effective_from', '>', $dari)
                    ->when(! $this->option('timpa'), fn ($q) => $q->where('amount', '<=', 0))
                    ->delete();

                // Baris yang mulainya persis di --dari: koreksi, ditimpa.
                $sama = $employee->salaries()
                    ->where('salary_component_id', $komponen->id)
                    ->whereDate('effective_from', $dari)
                    ->first();

                if ($sama !== null) {
                    $sama->update(['amount' => $jumlah, 'effective_to' => null]);
                } else {
                    $employee->salaries()->create([
                        'salary_component_id' => $komponen->id,
                        'amount' => $jumlah,
                        'effective_from' => $dari->toDateString(),
                    ]);
                }

                // Baris lama yang masih terbuka (atau menjangkau lewat --dari)
                // ditutup sehari sebelumnya, supaya tidak ada dua gaji yang
                // berlaku di tanggal yang sama.
                $employee->salaries()
                    ->where('salary_component_id', $komponen->id)
                    ->whereDate('effective_from', '<', $dari)
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $dari))
                    ->update(['effective_to' => $dari->copy()->subDay()->toDateString()]);
            }
        });

        $this->info($sasaran->count().' karyawan diperbarui. Kalau payroll periode ini sudah dihitung, hitung ulang supaya gaji barunya terpakai.');

        return self::SUCCESS;
    }

    /** @return ?Collection<int, Employee> */
    protected function sasaran(): ?Collection
    {
        $pins = array_map('strval', (array) $this->option('pin'));
        $divisi = (array) $this->option('divisi');

        if ($pins === [] && $divisi === []) {
            $this->error('Sebutkan sasarannya: --divisi=barista dan/atau --pin=17');
            $this->line('Divisi yang ada: '.Division::orderBy('name')->pluck('code')->implode(', '));

            return null;
        }

        $hasil = collect();

        foreach ($pins as $pin) {
            $employee = Employee::where('pin_device', $pin)->first();

            if ($employee === null) {
                $this->error("Tidak ada karyawan dengan PIN {$pin}.");

                return null;
            }

            $hasil->push($employee);
        }

        foreach ($divisi as $kode) {
            $division = Division::query()
                ->whereRaw('lower(code) = ?', [strtolower(trim($kode))])
                ->orWhereRaw('lower(name) = ?', [strtolower(trim($kode))])
                ->first();

            if ($division === null) {
                $this->error("Divisi \"{$kode}\" tidak ada. Pilihan: ".Division::orderBy('name')->pluck('code')->implode(', '));

                return null;
            }

            // Divisi UTAMA saja. Dava & Farrel tercatat juga di Waiters sebagai
            // divisi sekunder; gaji mengikuti posisi utamanya.
            $anggota = Employee::query()
                ->active()
                ->with('divisions')
                ->get()
                ->filter(fn (Employee $e) => $e->primaryDivision()?->id === $division->id);

            $hasil = $hasil->merge($anggota);
        }

        return $hasil->unique('id')->sortBy('name')->values();
    }
}
