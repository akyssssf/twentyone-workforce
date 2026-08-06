<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashAdvanceInstallment extends Model
{
    protected $fillable = [
        'cash_advance_id', 'payroll_period_id', 'sequence',
        'amount', 'status', 'payslip_item_id',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'sequence' => 'integer'];
    }

    public function cashAdvance(): BelongsTo
    {
        return $this->belongsTo(CashAdvance::class);
    }
}
