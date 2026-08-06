<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Division extends Model
{
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'color', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_divisions')
            ->withPivot(['is_primary', 'competency_level'])
            ->withTimestamps();
    }

    public function staffingRequirements(): HasMany
    {
        return $this->hasMany(StaffingRequirement::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
