<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris rincian di slip gaji.
 *
 * label dan rule_snapshot sengaja DISALIN, bukan dirujuk: slip yang sudah
 * diterima karyawan tidak boleh berubah isinya hanya karena manager mengganti
 * nama komponen gaji atau menyesuaikan tarif tahun depan.
 */
class PayslipItem extends Model
{
    protected $fillable = [
        'payslip_id', 'salary_component_id', 'category', 'label', 'qty', 'rate',
        'amount', 'source_type', 'source_id', 'rule_snapshot', 'sort_order', 'note',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'rate' => 'integer',
            'amount' => 'integer',
            'rule_snapshot' => 'array',
        ];
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
