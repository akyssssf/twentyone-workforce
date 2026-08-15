<?php

namespace App\Models;

use App\Enums\OvertimeOccasion;
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
        'request_id', 'batch_id', 'work_date', 'shift_id', 'occasion', 'planned_start',
        'planned_end', 'planned_minutes', 'initiated_by', 'is_backdated', 'reason',
        'secret_code',
    ];

    /**
     * Kode yang dipegang karyawan yang ditunjuk.
     *
     * Huruf yang mudah tertukar saat dibacakan lewat telepon sengaja dibuang:
     * tidak ada 0/O, 1/I, 5/S, 8/B. Kode yang salah dengar berarti orangnya
     * gagal mengaktifkan lembur di tengah malam, dan tidak ada yang bisa
     * membantunya saat itu.
     */
    public static function generateCode(): string
    {
        // Tanpa 0/O, 1/I, 5/S, 8/B — pasangan yang paling sering salah
        // dengar saat kode dibacakan lewat telepon di dapur yang berisik.
        $abjad = 'ACDEFGHJKLMNPQRTUVWXYZ234679';
        $kode = '';

        for ($i = 0; $i < 6; $i++) {
            $kode .= $abjad[random_int(0, strlen($abjad) - 1)];
        }

        return static::where('secret_code', $kode)->exists() ? static::generateCode() : $kode;
    }

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'occasion' => OvertimeOccasion::class,
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
