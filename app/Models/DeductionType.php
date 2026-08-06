<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeductionType extends Model
{
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'is_system', 'default_amount', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'default_amount' => 'integer',
        ];
    }

    /** Yang boleh diinput manual manager: selain yang dihitung sistem. */
    public function scopeManual($query)
    {
        return $query->where('is_system', false)->where('is_active', true);
    }
}
