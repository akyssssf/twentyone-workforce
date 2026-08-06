<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\OvertimeRecord;
use Illuminate\Support\Carbon;

/**
 * Menjawab satu pertanyaan: berapa menit lembur yang DIAKUI untuk hari ini.
 *
 * Jawabannya tidak pernah datang dari jam scan semata. Menit setelah jam
 * pulang, betapa pun banyaknya, bukan lembur kalau tidak ada approval (BR-14).
 * Jadi kelas ini hanya membaca overtime_records yang sudah dikonfirmasi —
 * bukan menghitung sendiri dari selisih waktu.
 *
 * Dipisah dari AttendanceComputer supaya aturan "lembur tidak otomatis" punya
 * satu tempat yang jelas, dan supaya mengubahnya nanti tidak perlu menyentuh
 * perhitungan absensi.
 */
class OvertimeResolver
{
    public function minutesFor(Employee $employee, Carbon $workDate): int
    {
        return (int) OvertimeRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate)
            ->confirmed()
            ->sum('payable_minutes');
    }
}
