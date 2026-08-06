<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use App\Models\Concerns\HasShiftKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu penugasan: karyawan X, tanggal Y, shift Z, sebagai divisi D.
 *
 * Inilah SATU-SATUNYA jawaban atas "hari ini dia shift apa". employees
 * .default_shift_id dan preferred_off_days hanya preferensi untuk generator.
 *
 * Setiap karyawan punya baris untuk SETIAP hari, termasuk hari libur. Kalau
 * libur diwakili ketiadaan baris, sistem tidak bisa membedakan "dia libur"
 * dari "belum dijadwalkan" - dua hal yang berbeda konsekuensinya.
 */
class RosterAssignment extends Model
{
    use HasShiftKey;

    protected $fillable = [
        'roster_id', 'employee_id', 'work_date', 'shift_id', 'shift_key',
        'division_id', 'status', 'source', 'source_request_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'status' => AssignmentStatus::class,
        ];
    }

    public function roster(): BelongsTo
    {
        return $this->belongsTo(Roster::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function scopeWorking($query)
    {
        return $query->where('status', AssignmentStatus::Scheduled->value)->whereNotNull('shift_id');
    }

    public function scopeOnDate($query, Carbon $date)
    {
        return $query->whereDate('work_date', $date);
    }

    /** Jam mulai dan selesai sesungguhnya, sudah memperhitungkan lewat tengah malam. */
    public function startsAt(): ?Carbon
    {
        return $this->shift?->startsOn($this->work_date);
    }

    public function endsAt(): ?Carbon
    {
        return $this->shift?->endsOn($this->work_date);
    }
}
