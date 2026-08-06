<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Enums\AttendanceStatus;
use App\Models\Concerns\HasShiftKey;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attendance extends Model
{
    use HasFactory, HasShiftKey;

    protected $fillable = [
        'employee_id',
        'shift_id',
        'shift_key',
        'roster_assignment_id',
        'division_id',
        'work_date',
        'scheduled_in',
        'scheduled_out',
        'check_in_at',
        'check_out_at',
        'late_seconds',
        'late_minutes',
        'early_leave_seconds',
        'early_leave_minutes',
        'work_minutes',
        'overtime_minutes',
        'first_log_id',
        'last_log_id',
        'has_adjustment',
        'is_closed',
        'closed_at',
        'source_note',
        'status',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'scheduled_in' => 'datetime',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'scheduled_out' => 'datetime',
            'late_seconds' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_seconds' => 'integer',
            'early_leave_minutes' => 'integer',
            'work_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'has_adjustment' => 'boolean',
            'is_closed' => 'boolean',
            'closed_at' => 'datetime',
            'status' => AttendanceStatus::class,
            'computed_at' => 'datetime',
        ];
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function rosterAssignment(): BelongsTo
    {
        return $this->belongsTo(RosterAssignment::class);
    }

    public function overtimeRecords(): HasMany
    {
        return $this->hasMany(OvertimeRecord::class);
    }

    /** Terlambat dan pulang cepat adalah ANGKA, bukan status tersendiri. */
    public function isLate(): bool
    {
        return $this->late_minutes > 0;
    }

    public function isEarlyLeave(): bool
    {
        return $this->early_leave_minutes > 0;
    }

    public function hasOvertime(): bool
    {
        return $this->overtime_minutes > 0;
    }

    public function scopeInPeriod($query, $start, $end)
    {
        return $query->whereBetween('work_date', [$start, $end]);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('work_date', $date);
    }

    public function scopeForMonth($query, int $year, int $month)
    {
        return $query->whereYear('work_date', $year)->whereMonth('work_date', $month);
    }

    public function scopeLate($query)
    {
        return $query->where('status', 'telat');
    }
}
