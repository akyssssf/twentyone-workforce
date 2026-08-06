<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashAdvance extends Model
{
    protected $fillable = [
        'employee_id', 'amount', 'installments_count', 'reason',
        'status', 'approved_by', 'disbursed_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'disbursed_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(CashAdvanceInstallment::class)->orderBy('sequence');
    }

    public function remaining(): int
    {
        return (int) $this->installments()->where('status', 'scheduled')->sum('amount');
    }
}
