<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bonus dan potongan manual dalam satu tabel: siklus hidupnya identik (dibuat
 * manager, WAJIB beralasan, terikat satu periode, berakhir sebagai baris slip).
 * UI tetap menampilkannya sebagai dua menu terpisah sesuai brief.
 */
class ManualPayrollEntry extends Model
{
    protected $fillable = [
        'employee_id', 'payroll_period_id', 'entry_type', 'deduction_type_id',
        'amount', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function deductionType(): BelongsTo
    {
        return $this->belongsTo(DeductionType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeBonus($query)
    {
        return $query->where('entry_type', 'bonus');
    }

    public function scopeDeduction($query)
    {
        return $query->where('entry_type', 'deduction');
    }
}
