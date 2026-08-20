<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use App\Models\Concerns\HasShiftKey;
use App\Services\Attendance\WorkWindow;
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
        'start_time_override', 'end_time_override',
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

    /**
     * Jam mulai efektif hari itu: jam khusus kalau ada, kalau tidak ikut
     * master shift. "HH:MM:SS", atau null kalau memang libur.
     */
    public function mulaiEfektif(): ?string
    {
        return $this->start_time_override ?? $this->shift?->start_time;
    }

    public function selesaiEfektif(): ?string
    {
        return $this->end_time_override ?? $this->shift?->end_time;
    }

    /** Hari ini jamnya menyimpang dari master shift? */
    public function pakaiJamKhusus(): bool
    {
        return $this->start_time_override !== null || $this->end_time_override !== null;
    }

    /** Jam mulai dan selesai sesungguhnya, sudah memperhitungkan lewat tengah malam. */
    public function startsAt(): ?Carbon
    {
        if ($this->shift === null) {
            return null;
        }

        return WorkWindow::for($this->shift, $this->work_date, $this)->scheduledIn;
    }

    public function endsAt(): ?Carbon
    {
        if ($this->shift === null) {
            return null;
        }

        return WorkWindow::for($this->shift, $this->work_date, $this)->scheduledOut;
    }
}
