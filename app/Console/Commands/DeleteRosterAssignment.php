<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\ShiftSwapRequest;
use App\Support\DateInput;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Hapus SATU baris roster milik seseorang pada satu tanggal.
 *
 * Ada karena `roster:set` sengaja TIDAK bisa melakukannya: dia memindahkan
 * shift, dan baris ber-source `leave`/`swap` dilindunginya supaya keputusan
 * yang sudah disetujui tidak hilang diam-diam. Perlindungan itu benar, tapi
 * akibatnya baris dobel yang keliru — dua shift yang jamnya tumpang tindih di
 * hari yang sama, yang mustahil dijalani satu orang — tidak punya jalur
 * pembersih sama sekali selain menyentuh database langsung.
 *
 * Baris dobel seperti itu bukan cuma kotor: shift kedua ikut merebut scan, jadi
 * jam pulang shift yang benar hilang dan shift yang keliru mendapat jam masuk
 * palsu berikut telat berjam-jam. Itu potongan gaji atas shift yang tidak
 * pernah dijalani.
 */
class DeleteRosterAssignment extends Command
{
    protected $signature = 'roster:hapus
                            {pin : PIN karyawan di mesin}
                            {tanggal : Tanggal (YYYY-MM-DD)}
                            {shift : Kode shift baris yang mau dihapus, mis. malam}';

    protected $description = 'Hapus satu baris roster seseorang pada satu tanggal';

    public function handle(): int
    {
        $employee = Employee::where('pin_device', (string) $this->argument('pin'))->first();

        if ($employee === null) {
            $this->error("Tidak ada karyawan dengan PIN {$this->argument('pin')}.");

            return self::FAILURE;
        }

        $tanggal = DateInput::parseOrFail((string) $this->argument('tanggal'), 'tanggal');
        $kode = strtolower(trim((string) $this->argument('shift')));

        $shift = Shift::where('code', $kode)->first();

        if ($shift === null) {
            $this->error("Shift dengan kode '{$kode}' tidak ada.");
            $this->line('Yang tersedia: '.Shift::pluck('code')->implode(', '));

            return self::FAILURE;
        }

        $this->line("Karyawan: {$employee->name}  Tanggal: {$tanggal->toDateString()}");
        $this->line('Sebelum : '.$this->ringkas($employee, $tanggal));

        $baris = RosterAssignment::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $tanggal)
            ->where('shift_id', $shift->id)
            ->first();

        if ($baris === null) {
            $this->error("{$employee->name} tidak punya baris {$shift->name} pada tanggal itu.");

            return self::FAILURE;
        }

        if (($pengajuan = $this->pengajuanYangMerujuk($baris)) !== null) {
            // Migrasi memasang cascadeOnDelete dari shift_swap_requests ke
            // roster_assignments. Menghapus baris yang jadi requester_assignment
            // sebuah pengajuan tukar akan IKUT MENGHAPUS pengajuannya —
            // riwayat keputusan yang sudah disetujui lenyap tanpa jejak, dan
            // tidak ada yang memberi tahu. Ditolak di sini, bukan dibiarkan.
            $this->error("Baris ini dirujuk pengajuan tukar {$pengajuan} sebagai jadwal pengaju.");
            $this->line('Menghapusnya akan ikut menghapus riwayat pengajuan itu (cascade).');
            $this->line('Batalkan atau selesaikan pengajuannya dulu lewat panel manajer.');

            return self::FAILURE;
        }

        $sisa = RosterAssignment::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $tanggal)
            ->count();

        $baris->delete();

        $this->info("Baris {$shift->name} dihapus.");

        if ($sisa <= 1) {
            // Tidak punya baris sama sekali BUKAN sama dengan libur: shift-nya
            // jadi hasil tebakan dari jam scan, dan tidak masuk sama sekali
            // tidak lagi terhitung alpha.
            $this->warn('Sekarang dia tidak punya baris roster sama sekali di tanggal itu.');
            $this->line('Kalau maksudnya libur, tandai eksplisit: <info>php artisan roster:set '
                .$employee->pin_device.' '.$tanggal->toDateString().'=libur</info>');
        }

        Artisan::call('attendance:compute', [
            '--from' => $tanggal->toDateString(),
            '--to' => $tanggal->toDateString(),
        ]);

        $this->line('Sesudah : '.$this->ringkas($employee, $tanggal));

        return self::SUCCESS;
    }

    /** Kode pengajuan tukar yang memakai baris ini sebagai jadwal pengaju, kalau ada. */
    protected function pengajuanYangMerujuk(RosterAssignment $baris): ?string
    {
        $swap = ShiftSwapRequest::query()
            ->with('request')
            ->where('requester_assignment_id', $baris->id)
            ->first();

        return $swap?->request?->code ?? ($swap !== null ? '#'.$swap->request_id : null);
    }

    protected function ringkas(Employee $employee, $tanggal): string
    {
        $baris = RosterAssignment::with('shift')
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $tanggal)
            ->get();

        if ($baris->isEmpty()) {
            return '(tidak ada baris roster)';
        }

        return $baris->map(fn (RosterAssignment $b) => sprintf('%s (%s)',
            $b->shift?->name ?? 'LIBUR',
            $b->source ?? 'manual',
        ))->implode(' + ');
    }
}
