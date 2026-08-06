<?php

namespace App\Models;

use App\Enums\RuleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Sekumpulan aturan yang berlaku dalam satu rentang tanggal.
 *
 * Tidak pernah diedit di tempat. Mengubah tarif = menutup yang lama
 * (effective_to) dan membuat yang baru, supaya slip gaji yang sudah terbit
 * tidak ikut berubah angkanya.
 */
class RuleSet extends Model
{
    protected $fillable = [
        'branch_id', 'type', 'name', 'effective_from', 'effective_to',
        'is_active', 'created_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'type' => RuleType::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(RuleTier::class)->orderBy('min_value');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeEffectiveOn($query, Carbon $date)
    {
        return $query->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }
}
