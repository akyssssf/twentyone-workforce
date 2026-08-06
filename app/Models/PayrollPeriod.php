<?php

namespace App\Models;

use App\Enums\PayrollStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Periode penggajian 21 s/d 20, BUKAN bulan kalender.
 *
 * Slip 21 Agustus menghitung kerja 21 Juli - 20 Agustus, sehingga data absensi
 * sudah lengkap saat payroll digenerate dan tidak ada hari yang perlu ditebak.
 */
class PayrollPeriod extends Model
{
    protected $fillable = [
        'branch_id', 'code', 'start_date', 'end_date', 'pay_date', 'status',
        'approved_by', 'approved_at', 'locked_by', 'locked_at',
        'reopened_by', 'reopened_at', 'reopen_reason',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'pay_date' => 'date',
            'status' => PayrollStatus::class,
            'approved_at' => 'datetime',
            'locked_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(PayrollRun::class);
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /** Run yang berlaku: versi terakhir yang selesai. */
    public function activeRun(): ?PayrollRun
    {
        return $this->runs()->where('status', 'completed')->latest('version')->first();
    }

    public function manualEntries(): HasMany
    {
        return $this->hasMany(ManualPayrollEntry::class);
    }

    public function label(): string
    {
        return $this->start_date->translatedFormat('d M') . ' – ' . $this->end_date->translatedFormat('d M Y');
    }

    public function covers(Carbon $date): bool
    {
        return $date->betweenIncluded($this->start_date, $this->end_date);
    }

    public function isLocked(): bool
    {
        return $this->status === PayrollStatus::Locked;
    }
}
