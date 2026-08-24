<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftSwapRequest extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'request_id';

    public $incrementing = false;

    /** Tukar hari libur antara dua orang, bukan tukar shift satu tanggal. */
    public const KIND_LIBUR = 'libur';

    protected $fillable = [
        'request_id', 'kind', 'requester_assignment_id', 'partner_employee_id',
        'partner_assignment_id', 'requester_assignment_2_id', 'partner_assignment_2_id',
        'partner_accepted_at', 'partner_rejected_at', 'partner_note', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'partner_accepted_at' => 'datetime',
            'partner_rejected_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function requesterAssignment(): BelongsTo
    {
        return $this->belongsTo(RosterAssignment::class, 'requester_assignment_id');
    }

    public function partnerAssignment(): BelongsTo
    {
        return $this->belongsTo(RosterAssignment::class, 'partner_assignment_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'partner_employee_id');
    }

    /** Pasangan baris kedua — hanya terisi pada tukar libur. */
    public function requesterAssignment2(): BelongsTo
    {
        return $this->belongsTo(RosterAssignment::class, 'requester_assignment_2_id');
    }

    public function partnerAssignment2(): BelongsTo
    {
        return $this->belongsTo(RosterAssignment::class, 'partner_assignment_2_id');
    }

    public function isTukarLibur(): bool
    {
        return $this->kind === self::KIND_LIBUR;
    }
}
