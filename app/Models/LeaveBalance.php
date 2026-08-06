<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveBalance extends Model
{
    protected $fillable = [
        'employee_id', 'leave_type_id', 'year', 'entitlement_days',
        'carried_over_days', 'used_days', 'pending_days', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'entitlement_days' => 'decimal:1',
            'carried_over_days' => 'decimal:1',
            'used_days' => 'decimal:1',
            'pending_days' => 'decimal:1',
            'expires_at' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(LeaveLedger::class);
    }

    /**
     * Sisa yang boleh diajukan.
     *
     * pending_days ikut dikurangi supaya karyawan tidak bisa mengajukan
     * 12 hari cuti tiga kali sebelum satu pun diputuskan.
     */
    public function remaining(): float
    {
        return (float) $this->entitlement_days
            + (float) $this->carried_over_days
            - (float) $this->used_days
            - (float) $this->pending_days;
    }
}
