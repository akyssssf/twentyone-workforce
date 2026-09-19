<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Shift extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'start_time',
        'end_time',
        'show_hours',
        'crosses_midnight',
        'break_minutes',
        'is_break_paid',
        'window_before_hours',
        'window_after_hours',
        'overtime_starts_after_minutes',
        'color',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            // Sengaja tidak di-cast ke datetime. Ini jam dinding tanpa
            // tanggal, jadi dibiarkan string "HH:MM:SS" supaya tidak
            // ketempelan tanggal hari ini secara diam-diam.
            'is_active' => 'boolean',
            'show_hours' => 'boolean',
            'crosses_midnight' => 'boolean',
            'is_break_paid' => 'boolean',
            'break_minutes' => 'integer',
            'window_before_hours' => 'integer',
            'window_after_hours' => 'integer',
            'overtime_starts_after_minutes' => 'integer',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RosterAssignment::class);
    }

    public function timeOverrides(): HasMany
    {
        return $this->hasMany(ShiftTimeOverride::class);
    }

    /**
     * Jam yang BERLAKU pada satu tanggal — bukan sekadar jam master.
     *
     * Jam shift bisa berubah di tengah jalan ("mulai 21 September Shift 2
     * tutup 23:30"), dan perubahan seperti itu tidak boleh menyentuh tanggal
     * yang sudah lewat. Semua perhitungan yang butuh jam shift harus lewat
     * sini, bukan membaca start_time/end_time langsung — yang membaca kolom
     * mentah akan diam-diam memakai jam yang salah untuk tanggal lama.
     *
     * @return array{start_time: string, end_time: string, override: ?ShiftTimeOverride}
     */
    public function jamPada(Carbon $workDate): array
    {
        $override = $this->timeOverrides
            ->first(fn (ShiftTimeOverride $o) => $o->mencakup($workDate));

        return [
            'start_time' => (string) ($override?->start_time ?? $this->start_time),
            'end_time' => (string) ($override?->end_time ?? $this->end_time),
            'override' => $override,
        ];
    }

    /** Jam masuk sesungguhnya untuk satu tanggal kerja. */
    public function startsOn(Carbon $workDate): Carbon
    {
        return $this->applyTime($workDate, $this->jamPada($workDate)['start_time']);
    }

    /**
     * Jam pulang sesungguhnya. Untuk shift malam (17:00-01:00) hasilnya jatuh
     * di tanggal berikutnya — inilah yang membuat "absensi tanggal 6" tidak
     * sama dengan "scan yang tanggalnya 6".
     */
    public function endsOn(Carbon $workDate): Carbon
    {
        $start = $this->startsOn($workDate);
        $end = $this->applyTime($workDate, $this->jamPada($workDate)['end_time']);

        return $end->lessThanOrEqualTo($start) ? $end->addDay() : $end;
    }

    /** Menit kerja terjadwal. Istirahat ikut dibayar (D-02), jadi tidak dipotong. */
    public function scheduledMinutes(): int
    {
        $minutes = $this->startsOn(Carbon::now())->diffInMinutes($this->endsOn(Carbon::now()));

        return $this->is_break_paid ? $minutes : max(0, $minutes - $this->break_minutes);
    }

    protected function applyTime(Carbon $date, string $time): Carbon
    {
        [$hour, $minute, $second] = array_pad(array_map('intval', explode(':', $time)), 3, 0);

        return $date->copy()->startOfDay()->setTime($hour, $minute, $second);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'default_shift_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
