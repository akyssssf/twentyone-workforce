<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'employee_no',
        'pin_device',
        'name',
        'email',
        'phone',
        'default_shift_id',
        'employment_status',
        'is_active',
        'joined_at',
        'resigned_at',
        'preferred_off_days',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'joined_at' => 'date',
            'resigned_at' => 'date',
            'preferred_off_days' => 'array',
        ];
    }

    /**
     * Apakah tanggal ini hari libur mingguan karyawan ini.
     *
     * Sejak roster ada, ini cuma PREFERENSI yang dipakai generator roster —
     * bukan jawaban atas "hari ini dia libur?". Itu tugas roster_assignments.
     */
    public function isOffDay(Carbon $date): bool
    {
        return in_array($date->dayOfWeek, $this->preferred_off_days ?? [], true);
    }

    /**
     * @return array<int, int>
     */
    public function offDays(): array
    {
        return $this->preferred_off_days ?? [];
    }

    /**
     * Nomor HP diseragamkan di model, bukan di tiap command, supaya jalur mana
     * pun yang menulis (command, impor CSV, form web nanti) menghasilkan
     * bentuk yang sama.
     */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => PhoneNumber::normalize($value));
    }

    /**
     * Shift preferensi, BUKAN jadwal.
     *
     * Setelah roster bulanan ada, shift seseorang berbeda tiap tanggal dan
     * jawabannya cuma boleh datang dari roster_assignments. Kolom ini tinggal
     * dipakai sebagai titik awal saat men-generate roster bulan berikutnya.
     */
    public function defaultShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'default_shift_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(EmployeeDevice::class);
    }

    public function divisions(): BelongsToMany
    {
        return $this->belongsToMany(Division::class, 'employee_divisions')
            ->withPivot(['is_primary', 'competency_level'])
            ->withTimestamps();
    }

    public function primaryDivision(): ?Division
    {
        return $this->divisions->firstWhere('pivot.is_primary', true)
            ?? $this->divisions->first();
    }

    public function rosterAssignments(): HasMany
    {
        return $this->hasMany(RosterAssignment::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(Request::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function salaries(): HasMany
    {
        return $this->hasMany(EmployeeSalary::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function overtimeRecords(): HasMany
    {
        return $this->hasMany(OvertimeRecord::class);
    }

    /** Gaji pokok yang berlaku pada tanggal tertentu. */
    public function baseSalaryOn(Carbon $date): int
    {
        return (int) $this->salaries()
            ->whereHas('component', fn ($q) => $q->where('code', 'gaji_pokok'))
            ->effectiveOn($date)
            ->orderByDesc('effective_from')
            ->value('amount');
    }

    /** PIN yang berlaku pada tanggal tertentu. */
    public function pinOn(Carbon $date): ?string
    {
        return $this->devices()->activeOn($date)->value('pin');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Scan mentah milik karyawan ini, dijodohkan lewat PIN mesin.
     *
     * attendance_logs.employee_id adalah hasil pencocokan PIN lewat
     * employee_devices yang berlaku pada tanggal scan — kolom turunan yang
     * boleh dihitung ulang. Scan dari PIN tak dikenal tetap masuk dengan
     * employee_id null supaya tidak ada data yang hilang.
     */
    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeEmployed($query)
    {
        return $query->where('employment_status', 'active');
    }
}
