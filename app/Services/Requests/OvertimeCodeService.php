<?php

namespace App\Services\Requests;

use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\OvertimeRequest;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Aktivasi lembur lewat kode rahasia.
 *
 * Manajer menunjuk satu orang; orang itu menerima kode; hanya dia yang bisa
 * mengaktifkan lembur tersebut.
 *
 * Kenapa perlu kode, padahal sudah ada approval: approval menjawab "lembur ini
 * disetujui", sedangkan kode menjawab "orang yang mengerjakannya benar orang
 * yang ditunjuk". Tanpa itu, di malam yang sibuk siapa pun yang kebetulan masih
 * di tempat bisa mengaku sebagai yang ditugaskan, dan pengesahan realisasi jadi
 * bergantung pada ingatan manajer keesokan harinya.
 *
 * Lembur yang tidak diaktifkan tidak dibayar — sama seperti lembur tanpa
 * approval sama sekali.
 */
class OvertimeCodeService
{
    /**
     * Karyawan mengaktifkan lembur miliknya dengan kode.
     *
     * @throws RuntimeException
     */
    public function activate(Employee $employee, string $kode): OvertimeRecord
    {
        // Dibersihkan supaya kode yang disalin dari WhatsApp lengkap dengan
        // spasi atau huruf kecil tetap diterima.
        $kode = strtoupper(trim($kode));

        if ($kode === '') {
            throw new RuntimeException('Masukkan kode lembur Anda.');
        }

        $overtime = OvertimeRequest::query()
            ->with('request')
            ->where('secret_code', $kode)
            ->first();

        if ($overtime === null) {
            throw new RuntimeException('Kode lembur tidak dikenali. Periksa lagi huruf dan angkanya.');
        }

        $request = $overtime->request;

        // Kode milik orang lain. Pesannya sengaja tidak menyebut siapa
        // pemiliknya — itu bukan urusan orang yang salah memasukkan kode.
        if ($request->employee_id !== $employee->id) {
            throw new RuntimeException('Kode ini bukan untuk Anda.');
        }

        if ($request->status->value !== 'approved') {
            throw new RuntimeException('Lembur ini belum disetujui manajer, jadi belum bisa diaktifkan.');
        }

        $record = OvertimeRecord::query()
            ->where('overtime_request_id', $overtime->request_id)
            ->where('employee_id', $employee->id)
            ->first();

        if ($record === null) {
            throw new RuntimeException('Catatan lembur belum dibuat. Hubungi manajer.');
        }

        if ($record->isActivated()) {
            throw new RuntimeException(
                'Lembur ini sudah diaktifkan pada ' . $record->activated_at->translatedFormat('d M Y H:i') . '.'
            );
        }

        // Kode hanya berlaku di sekitar tanggal lemburnya. Tanpa batas ini,
        // kode lama masih bisa dipakai berminggu-minggu kemudian.
        $tanggal = $overtime->work_date;

        if (! Carbon::today()->betweenIncluded($tanggal->copy()->subDay(), $tanggal->copy()->addDay())) {
            throw new RuntimeException(
                'Kode ini hanya berlaku di sekitar tanggal lembur ('
                . $tanggal->translatedFormat('d M Y') . ').'
            );
        }

        $record->update([
            'activated_at' => now(),
            'activated_by' => auth()->id(),
        ]);

        AuditLogger::record('overtime.activated', $record, [], [
            'employee' => $employee->name,
            'work_date' => $tanggal->toDateString(),
        ]);

        return $record->fresh();
    }

    /**
     * Lembur milik karyawan ini yang menunggu diaktifkan.
     *
     * @return \Illuminate\Support\Collection<int, OvertimeRecord>
     */
    public function pendingFor(Employee $employee)
    {
        return OvertimeRecord::query()
            ->with('overtimeRequest')
            ->where('employee_id', $employee->id)
            ->whereNull('activated_at')
            ->where('status', 'pending_confirmation')
            ->whereDate('work_date', '>=', today()->subDay())
            ->orderBy('work_date')
            ->get();
    }
}
