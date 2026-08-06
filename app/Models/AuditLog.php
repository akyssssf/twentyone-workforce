<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_type', 'actor_id', 'actor_name', 'action', 'auditable_type',
        'auditable_id', 'old_values', 'new_values', 'ip', 'user_agent', 'context',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'context' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Bawaan halaman audit: hanya tindakan manusia.
     *
     * Tanpa saringan ini, log tenggelam oleh perubahan otomatis cron compute
     * tiap 15 menit dan approval cuti yang penting jadi tidak terlihat.
     */
    public function scopeByHuman($query)
    {
        return $query->where('actor_type', 'user');
    }
}
