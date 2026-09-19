<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu periode jam shift. Lihat migrasi create_shift_time_overrides_table.
 */
class ShiftTimeOverride extends Model
{
    protected $fillable = [
        'shift_id', 'effective_from', 'effective_to', 'start_time', 'end_time', 'note',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** Baris yang mencakup tanggal ini. */
    public function scopeBerlakuPada($query, Carbon $tanggal)
    {
        $hari = $tanggal->toDateString();

        return $query
            ->whereDate('effective_from', '<=', $hari)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $hari));
    }

    public function mencakup(Carbon $tanggal): bool
    {
        $hari = $tanggal->copy()->startOfDay();

        return $this->effective_from->lessThanOrEqualTo($hari)
            && ($this->effective_to === null || $this->effective_to->greaterThanOrEqualTo($hari));
    }
}
