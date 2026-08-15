<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\Shift;
use Illuminate\Support\Carbon;

/**
 * Menjawab satu pertanyaan: berapa menit lembur yang DIAKUI untuk hari ini.
 *
 * Lembur di sini berarti "menambah shift": begitu shift seseorang selesai, dia
 * lanjut bekerja sampai scan terakhirnya. Lamanya tidak diketik siapa pun —
 * dihitung dari jarak antara jam pulang terjadwal dan scan terakhir.
 *
 * Yang TIDAK berubah dari aturan lama: menit setelah jam pulang bukan lembur
 * kalau tidak ada penugasan (BR-14). Yang dihitung otomatis hanya durasinya,
 * bukan keberadaannya — tanpa penugasan admin dan aktivasi kode, hasilnya
 * tetap nol berapa lama pun orangnya bertahan di tempat.
 */
class OvertimeResolver
{
    /**
     * Menit lembur yang dibayar untuk satu karyawan pada satu tanggal.
     *
     * Kalau shift dan jam pulangnya diketahui, realisasinya sekalian diisi
     * dari scan sebelum dijumlahkan — dipanggil dari AttendanceComputer yang
     * memang sudah memegang kedua nilai itu.
     */
    public function minutesFor(
        Employee $employee,
        Carbon $workDate,
        ?Shift $shift = null,
        ?Carbon $scheduledOut = null,
    ): int {
        if ($shift !== null && $scheduledOut !== null) {
            $this->isiRealisasi($employee, $workDate, $shift, $scheduledOut);
        }

        return (int) OvertimeRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate)
            ->confirmed()
            ->sum('payable_minutes');
    }

    /**
     * Batas akhir hari kerja: jam pulang shift yang paling malam.
     *
     * Dipakai dua kali — sebagai ujung pencarian scan lembur, dan sebagai
     * pengganti kalau scan pulangnya tidak ada sama sekali.
     */
    public function batasAkhirHari(Carbon $workDate): Carbon
    {
        $shifts = Shift::query()->where('is_active', true)->get();

        $batas = null;

        foreach ($shifts as $shift) {
            $akhir = $shift->endsOn($workDate);

            if ($batas === null || $akhir->greaterThan($batas)) {
                $batas = $akhir;
            }
        }

        return $batas ?? $workDate->copy()->endOfDay();
    }

    /**
     * Shift yang berakhir setelah tengah malam tidak bisa lembur.
     *
     * Bukan pembatasan teknis melainkan keputusan operasional: setelah shift
     * malam kafenya tutup, jadi tidak ada pekerjaan yang bisa dilanjutkan.
     */
    public function bolehLembur(Shift $shift): bool
    {
        return ! $shift->crosses_midnight;
    }

    protected function isiRealisasi(Employee $employee, Carbon $workDate, Shift $shift, Carbon $scheduledOut): void
    {
        if (! $this->bolehLembur($shift)) {
            return;
        }

        $record = OvertimeRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate)

            // Tanpa aktivasi kode tidak ada bukti orang yang ditunjuk benar
            // yang mengerjakannya, jadi durasinya tidak usah dihitung.
            ->whereNotNull('activated_at')

            // Jangan menimpa angka yang sudah disahkan manusia. Manajer boleh
            // mengoreksi hasil hitungan ini, dan koreksinya harus bertahan
            // walau attendance:compute jalan lagi lima belas menit kemudian.
            ->whereNull('confirmed_by')

            ->first();

        if ($record === null) {
            return;
        }

        $batas = $this->batasAkhirHari($workDate);

        $scanTerakhir = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('scanned_at', [$scheduledOut, $batas])
            ->orderByDesc('scanned_at')
            ->first();

        // Tidak ada scan pulang: dianggap bekerja sampai kafe tutup. Ini
        // memang membayar paling banyak justru saat datanya paling tidak
        // pasti — pilihan sadar, supaya orang yang lembur tidak dirugikan
        // karena lupa menempel jari. Manajer bisa menurunkannya lewat
        // konfirmasi manual, dan setiap penurunan tercatat.
        $selesai = $scanTerakhir?->scanned_at?->copy() ?? $batas;

        $menit = max(0, (int) round($scheduledOut->diffInMinutes($selesai)));

        // Hari yang jendelanya belum tutup belum bisa disimpulkan: orangnya
        // mungkin masih bekerja. Angkanya tetap diperbarui supaya terlihat
        // berjalan, tapi belum disahkan sehingga belum ikut terbayar.
        $selesaiHari = Carbon::now()->greaterThanOrEqualTo($batas);

        $record->update([
            'actual_start' => $scheduledOut,
            'actual_end' => $selesai,
            'actual_minutes' => $menit,
            'payable_minutes' => min($menit, $record->approved_minutes),
            'status' => $selesaiHari ? 'confirmed' : 'pending_confirmation',
            'confirmed_at' => $selesaiHari ? Carbon::now() : null,
        ]);
    }
}
