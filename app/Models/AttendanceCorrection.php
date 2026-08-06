<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceCorrection extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'request_id';

    public $incrementing = false;

    protected $fillable = [
        'request_id', 'work_date', 'shift_key', 'correction_type',
        'proposed_check_in', 'proposed_check_out', 'proposed_status', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'proposed_check_in' => 'datetime',
            'proposed_check_out' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }
}
