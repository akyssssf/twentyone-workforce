<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'request_id';

    public $incrementing = false;

    protected $fillable = [
        'request_id', 'leave_type_id', 'start_date', 'end_date',
        'total_days', 'is_half_day', 'reason', 'handover_note',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'total_days' => 'decimal:1',
            'is_half_day' => 'boolean',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
