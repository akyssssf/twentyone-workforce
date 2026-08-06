<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceCallback extends Model
{
    use HasFactory;

    protected $fillable = [
        'cloud_id',
        'type',
        'trans_id',
        'payload',
        'ip',
        'parsed',
        'parse_error',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'parsed' => 'boolean',
            'received_at' => 'datetime',
        ];
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    /**
     * Antrian kerja parser: callback attlog yang belum dipindahkan ke
     * attendance_logs, urut dari yang paling lama.
     */
    public function scopeUnparsed($query)
    {
        return $query->where('parsed', false)->orderBy('received_at');
    }

    public function scopeAttlog($query)
    {
        return $query->where('type', 'attlog');
    }
}
