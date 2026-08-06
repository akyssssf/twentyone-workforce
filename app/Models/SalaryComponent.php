<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalaryComponent extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'category', 'calc_type', 'is_taxable', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_taxable' => 'boolean', 'is_active' => 'boolean'];
    }

    public function employeeSalaries(): HasMany
    {
        return $this->hasMany(EmployeeSalary::class);
    }
}
