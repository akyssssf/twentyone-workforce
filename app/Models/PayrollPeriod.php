<?php

namespace App\Models;

use App\Enums\PayrollStatus;
use App\Support\Settings;
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

    /**
     * Rentang yang DITULIS di slip dan halaman payroll: selalu pola baku
     * 21–20 yang diturunkan dari kode periode, bukan start_date/end_date.
     *
     * Keduanya bisa beda satu hari: gajian 21 September dihitung 21 Agt–21 Sep
     * (mengikuti slip luar), dan periode berikutnya mulai 22 Sep supaya hari
     * itu tidak dihitung dua kali. Karyawan tidak perlu tahu pergeseran
     * internal itu — yang mereka kenal "gaji 21 sampai 20". Batas hitung
     * sebenarnya ada di rentangHitung().
     */
    public function label(): string
    {
        [$tahun, $bulan] = array_map('intval', explode('-', $this->code));
        $mulaiHari = Settings::int('payroll.period_start_day', 21);
        $bayar = Carbon::create($tahun, $bulan, Settings::int('payroll.pay_day', 21));

        $mulai = $bayar->copy()->subMonthNoOverflow()->day($mulaiHari);
        $selesai = $bayar->copy()->day($mulaiHari)->subDay();

        return $mulai->translatedFormat('d M') . ' – ' . $selesai->translatedFormat('d M Y');
    }

    /** Rentang yang benar-benar dihitung (start_date–end_date), untuk manajer. */
    public function rentangHitung(): string
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
