<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REALISASI lembur: jam sebenarnya dari fingerprint.
 *
 * payable_minutes = min(approved, actual) secara bawaan. Manager boleh
 * menaikkannya, tapi harus dengan alasan tertulis.
 */
class OvertimeRecord extends Model
{
    protected $fillable = [
        'employee_id', 'work_date', 'overtime_request_id', 'attendance_id',
        'actual_start', 'actual_end', 'actual_minutes', 'approved_minutes',
        'payable_minutes', 'status', 'confirmed_by', 'confirmed_at', 'note',
        'activated_at', 'activated_by',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'actual_start' => 'datetime',
            'actual_end' => 'datetime',
            'actual_minutes' => 'integer',
            'approved_minutes' => 'integer',
            'payable_minutes' => 'integer',
            'confirmed_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function overtimeRequest(): BelongsTo
    {
        return $this->belongsTo(OvertimeRequest::class, 'overtime_request_id', 'request_id');
    }

    public function isActivated(): bool
    {
        return $this->activated_at !== null;
    }

    /**
     * Saran menit lembur dari jam scan pulang ASLI, dibanding jadwal pulang
     * shift orangnya hari itu — bukan tebakan baru, tinggal baca dari
     * Attendance yang sudah dihitung attendance:compute.
     *
     * Satu formula ini otomatis mencakup dua situasi yang kelihatannya beda:
     * lembur beberapa menit lewat jadwal, MAUPUN yang sampai "nyambung" ke
     * shift berikutnya (mis. Shift Pagi yang pulangnya jauh masuk ke jam
     * Shift Malam) — keduanya sama-sama cuma selisih jam pulang asli
     * terhadap jadwal, cuma beda besar angkanya.
     *
     * Cuma SARAN: admin tetap bisa timpa manual di form pengesahan. Null
     * kalau belum ada scan pulang sama sekali, supaya tidak ada yang
     * ketuker antara "belum scan" dengan "lembur 0 menit".
     */
    public function saranMenit(): ?int
    {
        $absensi = Attendance::query()
            ->where('employee_id', $this->employee_id)
            ->whereDate('work_date', $this->work_date)
            ->first();

        if ($absensi === null || $absensi->check_out_at === null || $absensi->scheduled_out === null) {
            return null;
        }

        $selisih = $absensi->check_out_at->diffInMinutes($absensi->scheduled_out, false);

        return max(0, -$selisih);
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }
}
