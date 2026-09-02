<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\ShiftSwapRequest;
use App\Support\DateInput;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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
                            {tanggal : Tanggal, atau rentang 2026-09-01..2026-09-30}
                            {shift? : Kode shift. Kosong berarti SEMUA baris di tanggal itu}';

    protected $description = 'Hapus baris roster seseorang pada satu tanggal atau serentang';

    public function handle(): int
    {
        $employee = Employee::where('pin_device', (string) $this->argument('pin'))->first();

        if ($employee === null) {
            $this->error("Tidak ada karyawan dengan PIN {$this->argument('pin')}.");

            return self::FAILURE;
        }

        try {
            [$dari, $sampai] = $this->rentang((string) $this->argument('tanggal'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $shift = null;

        if ($kode = $this->argument('shift')) {
            $shift = Shift::where('code', strtolower(trim((string) $kode)))->first();

            if ($shift === null) {
                $this->error("Shift dengan kode '{$kode}' tidak ada.");
                $this->line('Yang tersedia: '.Shift::pluck('code')->implode(', '));

                return self::FAILURE;
            }
        }

        $baris = RosterAssignment::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$dari->copy()->startOfDay(), $sampai->copy()->endOfDay()])
            ->when($shift !== null, fn ($q) => $q->where('shift_id', $shift->id))
            ->orderBy('work_date')
            ->get();

        $this->line("Karyawan: {$employee->name}");
        $this->line('Rentang : '.$dari->toDateString().' s/d '.$sampai->toDateString());

        if ($baris->isEmpty()) {
            $this->error('Tidak ada baris roster yang cocok di rentang itu.');

            return self::FAILURE;
        }

        // Migrasi memasang cascadeOnDelete dari shift_swap_requests ke
        // roster_assignments. Menghapus baris yang jadi requester_assignment
        // sebuah pengajuan tukar akan IKUT MENGHAPUS pengajuannya — riwayat
        // keputusan yang sudah disetujui lenyap tanpa jejak, dan tidak ada yang
        // memberi tahu. Seluruh rentang diperiksa lebih dulu, jadi tidak ada
        // kemungkinan separuh terhapus lalu berhenti di tengah jalan.
        $terkunci = $baris->filter(fn (RosterAssignment $b) => $this->pengajuanYangMerujuk($b) !== null);

        if ($terkunci->isNotEmpty()) {
            $this->error($terkunci->count().' baris dirujuk pengajuan tukar sebagai jadwal pengaju:');

            foreach ($terkunci as $b) {
                $this->line('   '.$b->work_date->toDateString().'  '.($b->shift?->name ?? 'LIBUR')
                    .'  → pengajuan '.$this->pengajuanYangMerujuk($b));
            }

            $this->line('Menghapusnya akan ikut menghapus riwayat pengajuan itu (cascade).');
            $this->line('Batalkan atau selesaikan pengajuannya dulu lewat panel manajer.');

            return self::FAILURE;
        }

        $this->newLine();

        foreach ($baris as $b) {
            $this->line('   '.$b->work_date->translatedFormat('D, d M Y').'  '
                .($b->shift?->name ?? 'LIBUR').'  ('.($b->source ?? 'manual').')');
        }

        $this->newLine();

        if ($baris->count() > 1 && ! $this->confirm("Hapus {$baris->count()} baris ini?", false)) {
            $this->line('Dibatalkan, tidak ada yang dihapus.');

            return self::SUCCESS;
        }

        RosterAssignment::whereIn('id', $baris->pluck('id'))->delete();

        $this->info($baris->count().' baris roster dihapus.');

        // Tanggal yang barisnya hilang TIDAK sama dengan libur: shift-nya jadi
        // hasil tebakan dari jam scan, dan tidak masuk sama sekali tidak lagi
        // terhitung alpha. Aman untuk orang yang memang sudah keluar, menyesatkan
        // untuk orang yang masih bekerja.
        if ($employee->is_active) {
            $this->newLine();
            $this->warn($employee->name.' masih berstatus AKTIF.');
            $this->line('Tanggal tanpa baris roster tidak pernah terhitung alpha. Kalau maksudnya');
            $this->line('libur, tandai eksplisit dengan <info>roster:set</info> ...=libur, jangan dibiarkan kosong.');
        }

        Artisan::call('attendance:compute', [
            '--from' => $dari->toDateString(),
            '--to' => $sampai->toDateString(),
        ]);

        $this->newLine();
        $this->line('Sesudah : '.$this->ringkas($employee, $dari, $sampai));

        return self::SUCCESS;
    }

    /**
     * Satu tanggal, atau rentang "2026-09-01..2026-09-30".
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function rentang(string $teks): array
    {
        if (! str_contains($teks, '..')) {
            $t = DateInput::parseOrFail($teks, 'tanggal');

            return [$t, $t->copy()];
        }

        [$a, $b] = explode('..', $teks, 2);

        $dari = DateInput::parseOrFail(trim($a), 'tanggal awal');
        $sampai = DateInput::parseOrFail(trim($b), 'tanggal akhir');

        if ($sampai->lessThan($dari)) {
            throw new \InvalidArgumentException("Rentang terbalik: \"{$teks}\".");
        }

        return [$dari, $sampai];
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

    protected function ringkas(Employee $employee, Carbon $dari, Carbon $sampai): string
    {
        $sisa = RosterAssignment::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$dari->copy()->startOfDay(), $sampai->copy()->endOfDay()])
            ->count();

        return $sisa === 0
            ? 'tidak ada baris roster tersisa di rentang itu'
            : "{$sisa} baris roster masih tersisa di rentang itu";
    }
}
