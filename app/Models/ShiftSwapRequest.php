<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftSwapRequest extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'request_id';

    public $incrementing = false;

    protected $fillable = [
        'request_id', 'requester_assignment_id', 'partner_employee_id',
        'partner_assignment_id', 'partner_accepted_at', 'partner_rejected_at',
        'partner_note', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'partner_accepted_at' => 'datetime',
            'partner_rejected_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function requesterAssignment(): BelongsTo
    {
        return $this->belongsTo(RosterAssignment::class, 'requester_assignment_id');
    }

    public function partnerAssignment(): BelongsTo
    {
        return $this->belongsTo(RosterAssignment::class, 'partner_assignment_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'partner_employee_id');
    }
}
