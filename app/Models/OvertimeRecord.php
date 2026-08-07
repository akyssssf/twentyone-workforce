<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REALISASI lembur: jam sebenarnya dari fingerprint.
 *
 * payable_minutes = min(approved, actual) secara bawaan. Manager boleh
 * menaikkannya, tapi harus dengan alasan tertulis.
 */
class OvertimeRecord extends Model
{
    protected $fillable = [
        'employee_id', 'work_date', 'overtime_request_id', 'attendance_id',
        'actual_start', 'actual_end', 'actual_minutes', 'approved_minutes',
        'payable_minutes', 'status', 'confirmed_by', 'confirmed_at', 'note',
        'activated_at', 'activated_by',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'actual_start' => 'datetime',
            'actual_end' => 'datetime',
            'actual_minutes' => 'integer',
            'approved_minutes' => 'integer',
            'payable_minutes' => 'integer',
            'confirmed_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function overtimeRequest(): BelongsTo
    {
        return $this->belongsTo(OvertimeRequest::class, 'overtime_request_id', 'request_id');
    }

    public function isActivated(): bool
    {
        return $this->activated_at !== null;
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }
}
