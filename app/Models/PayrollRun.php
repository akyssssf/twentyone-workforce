<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu percobaan perhitungan payroll.
 *
 * Generate ulang tidak menimpa hasil lama: ia membuat versi baru dan menandai
 * yang lama `superseded`. Tanpa lapisan ini, "generate ulang" berarti
 * kehilangan bukti percobaan sebelumnya.
 */
class PayrollRun extends Model
{
    protected $fillable = [
        'payroll_period_id', 'version', 'status', 'rule_snapshot',
        'employee_count', 'total_take_home_pay', 'generated_by',
        'started_at', 'finished_at', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'rule_snapshot' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
