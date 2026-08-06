<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    protected $fillable = [
        'branch_id', 'source', 'file_path', 'original_name', 'uploaded_by',
        'rows_total', 'rows_inserted', 'rows_duplicate', 'rows_failed',
        'error_log', 'status', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'error_log' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
