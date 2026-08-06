<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveType extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'is_paid', 'deducts_balance', 'requires_evidence',
        'max_days_per_request', 'min_notice_days', 'default_entitlement_days', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'deducts_balance' => 'boolean',
            'requires_evidence' => 'boolean',
            'default_entitlement_days' => 'decimal:1',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Status absensi yang ditulis saat cuti jenis ini disetujui. */
    public function attendanceStatus(): string
    {
        return match ($this->code) {
            'sakit' => 'sakit',
            'izin' => 'izin',
            default => 'cuti',
        };
    }
}
