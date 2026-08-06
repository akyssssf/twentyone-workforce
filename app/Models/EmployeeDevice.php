<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Pemetaan PIN mesin ke karyawan, berlaku dalam rentang tanggal.
 *
 * Tanpa rentang ini, PIN yang dipakai ulang karyawan baru akan menarik seluruh
 * riwayat absensi karyawan lama ikut berpindah - diam-diam, tanpa error.
 */
class EmployeeDevice extends Model
{
    use SoftDeletes;

    protected $fillable = ['employee_id', 'cloud_id', 'pin', 'valid_from', 'valid_to', 'note'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Pemetaan yang berlaku pada tanggal tertentu, bukan pada hari ini. */
    public function scopeActiveOn($query, Carbon $date)
    {
        return $query->whereDate('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date));
    }

    /**
     * Begitu pemetaan baru dibuat, scan lama dari PIN itu yang tadinya
     * menggantung langsung dicocokkan ulang.
     *
     * Ini yang membuat alur "karyawan baru didaftarkan setelah sempat scan"
     * berjalan mulus, tanpa manager perlu tahu ada antrian yang harus dibereskan.
     */
    protected static function booted(): void
    {
        static::created(function (self $device) {
            AttendanceLog::query()
                ->whereNull('employee_id')
                ->where('pin', $device->pin)
                ->whereDate('scanned_at', '>=', $device->valid_from)
                ->when($device->valid_to, fn ($q) => $q->whereDate('scanned_at', '<=', $device->valid_to))
                ->update([
                    'employee_id' => $device->employee_id,
                    'resolved_at' => now(),
                ]);
        });
    }

    public static function resolveEmployeeId(string $pin, Carbon $date, ?string $cloudId = null): ?int
    {
        return static::query()
            ->where('pin', $pin)
            ->when($cloudId, fn ($q) => $q->where('cloud_id', $cloudId))
            ->activeOn($date)
            ->value('employee_id');
    }
}
