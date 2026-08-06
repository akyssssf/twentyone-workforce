<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payslip extends Model
{
    protected $fillable = [
        'payroll_run_id', 'employee_id', 'code', 'employee_snapshot',
        'total_earning', 'total_deduction', 'total_statutory', 'take_home_pay',
        'scheduled_days', 'present_days', 'absent_days', 'leave_days',
        'late_count', 'early_leave_count', 'overtime_minutes',
        'status', 'published_at', 'pdf_path', 'pdf_generated_at',
    ];

    protected function casts(): array
    {
        return [
            'employee_snapshot' => 'array',
            'total_earning' => 'integer',
            'total_deduction' => 'integer',
            'total_statutory' => 'integer',
            'take_home_pay' => 'integer',
            'published_at' => 'datetime',
            'pdf_generated_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayslipItem::class)->orderBy('sort_order');
    }

    public function earnings(): HasMany
    {
        return $this->items()->where('category', 'earning');
    }

    public function deductions(): HasMany
    {
        return $this->items()->where('category', 'deduction');
    }

    public function statutories(): HasMany
    {
        return $this->items()->where('category', 'statutory');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }
}
