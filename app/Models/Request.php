<?php

namespace App\Models;

use App\Enums\RequestStatus;
use App\Enums\RequestType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Induk semua pengajuan.
 *
 * Memegang segala yang sama untuk keempat jenis: kode, status, siapa
 * mengajukan, siapa memutuskan, kedaluwarsa. Yang khas per jenis ada di tabel
 * detail dengan request_id sebagai PK sekaligus FK.
 *
 * Tidak punya deleted_at: membatalkan pengajuan = status `cancelled`, bukan
 * menghapus baris. Riwayat siapa pernah mengajukan apa tidak boleh hilang.
 */
class Request extends Model
{
    protected $fillable = [
        'code', 'branch_id', 'type', 'employee_id', 'status', 'submitted_at',
        'decided_by', 'decided_at', 'decision_note', 'expires_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => RequestType::class,
            'status' => RequestStatus::class,
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function leave(): HasOne
    {
        return $this->hasOne(LeaveRequest::class);
    }

    public function overtime(): HasOne
    {
        return $this->hasOne(OvertimeRequest::class);
    }

    public function swap(): HasOne
    {
        return $this->hasOne(ShiftSwapRequest::class);
    }

    public function correction(): HasOne
    {
        return $this->hasOne(AttendanceCorrection::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(RequestAttachment::class);
    }

    /** Detail sesuai jenisnya, tanpa pemanggil perlu tahu tabel mana. */
    public function detail(): ?Model
    {
        return match ($this->type) {
            RequestType::Leave => $this->leave,
            RequestType::Overtime => $this->overtime,
            RequestType::Swap => $this->swap,
            RequestType::Correction => $this->correction,
        };
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', [
            RequestStatus::PendingPeer->value,
            RequestStatus::PendingManager->value,
        ]);
    }

    public function scopeAwaitingManager($query)
    {
        return $query->where('status', RequestStatus::PendingManager->value);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
