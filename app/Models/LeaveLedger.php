<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mutasi saldo cuti. Append-only, supaya "kok sisa cuti saya berkurang?" bisa dijawab. */
class LeaveLedger extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'leave_ledger';

    protected $fillable = [
        'leave_balance_id', 'request_id', 'delta_days', 'type', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return ['delta_days' => 'decimal:1'];
    }

    public function balance(): BelongsTo
    {
        return $this->belongsTo(LeaveBalance::class, 'leave_balance_id');
    }
}
