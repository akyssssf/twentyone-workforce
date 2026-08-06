<?php

namespace App\Models;

use App\Enums\RosterStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Roster extends Model
{
    protected $fillable = [
        'branch_id', 'period_year', 'period_month', 'status',
        'published_at', 'published_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'status' => RosterStatus::class,
            'published_at' => 'datetime',
            'period_year' => 'integer',
            'period_month' => 'integer',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RosterAssignment::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function startDate(): Carbon
    {
        return Carbon::create($this->period_year, $this->period_month, 1)->startOfDay();
    }

    public function endDate(): Carbon
    {
        return $this->startDate()->endOfMonth()->startOfDay();
    }

    public function label(): string
    {
        return $this->startDate()->translatedFormat('F Y');
    }

    /** Karyawan hanya boleh melihat roster yang sudah terbit. */
    public function scopeVisibleToEmployee($query)
    {
        return $query->whereIn('status', [RosterStatus::Published->value, RosterStatus::Locked->value]);
    }
}
