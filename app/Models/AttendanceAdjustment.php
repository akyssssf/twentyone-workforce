<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Koreksi absensi yang sudah disetujui. APPEND-ONLY.
 *
 * Dikunci ke (employee_id, work_date, shift_key), bukan ke attendance_id,
 * karena attendances adalah tabel turunan yang boleh dihapus dan dibangun
 * ulang. Kalau koreksi menempel ke id barisnya, recompute akan membuat
 * keputusan manager jadi yatim.
 *
 * Membatalkan koreksi = menambah baris `revert`, bukan menghapus baris lama.
 */
class AttendanceAdjustment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'employee_id', 'work_date', 'shift_key', 'request_id', 'type',
        'value_time', 'value_status', 'reason', 'evidence_path',
        'approved_by', 'approved_at', 'reverted_by_id',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'value_time' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    /**
     * Koreksi yang masih berlaku untuk satu hari kerja.
     *
     * shift_key 0 diperlakukan sebagai WILDCARD: berlaku untuk shift mana pun
     * di tanggal itu. Ini yang dipakai koreksi tingkat-hari seperti "hari ini
     * cuti", yang memang tidak menunjuk shift tertentu — dan juga koreksi
     * absensi yang diajukan karyawan, yang tidak tahu shift_key internal.
     */
    public function scopeEffectiveFor($query, int $employeeId, Carbon $workDate, int $shiftKey)
    {
        return $query->where('employee_id', $employeeId)
            ->whereDate('work_date', $workDate)
            ->whereIn('shift_key', array_unique([$shiftKey, 0]))
            ->whereNull('reverted_by_id')
            ->where('type', '!=', 'revert')
            ->orderBy('id');
    }
}
