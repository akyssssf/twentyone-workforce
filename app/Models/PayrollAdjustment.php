<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penyesuaian lintas periode.
 *
 * Koreksi yang datang setelah periode terkunci TIDAK membuka kunci apa pun -
 * selisihnya dibayar di periode berikutnya sebagai baris tersendiri. Slip yang
 * sudah diterima 18 orang tidak dibatalkan hanya karena kesalahan satu orang.
 */
class PayrollAdjustment extends Model
{
    protected $fillable = [
        'employee_id', 'origin_period_id', 'applied_period_id', 'amount',
        'reason', 'source_type', 'source_id', 'created_by', 'approved_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function originPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'origin_period_id');
    }
}
