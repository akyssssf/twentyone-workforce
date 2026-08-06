<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RENCANA lembur, disetujui sebelum dikerjakan (BR-13).
 *
 * Realisasinya ada di overtime_records. Dipisah karena lembur yang disetujui
 * 3 jam tapi orangnya pulang setelah 1 jam tidak boleh dibayar 3 jam.
 */
class OvertimeRequest extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'request_id';

    public $incrementing = false;

    protected $fillable = [
        'request_id', 'batch_id', 'work_date', 'shift_id', 'planned_start',
        'planned_end', 'planned_minutes', 'initiated_by', 'is_backdated', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'planned_minutes' => 'integer',
            'is_backdated' => 'boolean',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(OvertimeRecord::class, 'overtime_request_id', 'request_id');
    }
}
