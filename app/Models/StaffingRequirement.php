<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffingRequirement extends Model
{
    protected $fillable = [
        'branch_id', 'shift_id', 'division_id', 'day_type',
        'required_count', 'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'required_count' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }
}
