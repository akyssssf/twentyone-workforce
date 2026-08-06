<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleTier extends Model
{
    protected $fillable = [
        'rule_set_id', 'min_value', 'max_value', 'unit',
        'calc_type', 'value', 'label', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'min_value' => 'integer',
            'max_value' => 'integer',
            'value' => 'decimal:2',
        ];
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class);
    }

    public function matches(int $amount): bool
    {
        return $amount >= $this->min_value
            && ($this->max_value === null || $amount <= $this->max_value);
    }

    /** Salinan yang ikut dibekukan di slip gaji. */
    public function toSnapshot(): array
    {
        return [
            'rule_set_id' => $this->rule_set_id,
            'tier_id' => $this->id,
            'range' => [$this->min_value, $this->max_value],
            'unit' => $this->unit,
            'calc_type' => $this->calc_type,
            'value' => (float) $this->value,
            'label' => $this->label,
        ];
    }
}
