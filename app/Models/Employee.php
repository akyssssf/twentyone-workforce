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

    /**
     * Nilai bawaan di tingkat MODEL, bukan cuma di tingkat kolom database.
     *
     * Default kolom hanya berlaku saat baris ditulis; model yang baru dibuat
     * tetap memegang null sampai di-refresh. Akibatnya karyawan yang baru
     * ditambahkan lewat command atau impor akan dianggap "tidak diabsen" pada
     * pemanggilan pertama — kegagalan yang tidak menimbulkan error dan baru
     * ketahuan saat orangnya tidak pernah muncul di rekap.
     */
    protected $attributes = [
        'tracks_attendance' => true,
    ];

    protected $fillable = [
        'branch_id',
        'employee_no',
        'pin_device',
        'name',
        'email',
        'phone',
        'default_shift_id',
        'employment_status',
        'tracks_attendance',
        'photo_path',
        'is_active',
        'joined_at',
        'resigned_at',
        'preferred_off_days',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'tracks_attendance' => 'boolean',
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

    /**
     * Karyawan yang ikut diabsen dan dijadwalkan.
     *
     * Admin kafe punya akun dan tetap pegawai aktif, tapi tidak menempel jari
     * di mesin dan tidak masuk roster. Tanpa penyaring ini mereka muncul
     * sebagai Alpha setiap hari — dan potongan alpha ikut terhitung di payroll.
     *
     * Dipakai di SEMUA jalur yang menyentuh absensi: perhitungan harian,
     * generator roster, validasi kebutuhan shift, dan angka di dashboard.
     */
    public function scopeTracked($query)
    {
        return $query->where('is_active', true)->where('tracks_attendance', true);
    }

    /** Kebalikannya: admin & siapa pun yang tidak diabsen. */
    public function scopeUntracked($query)
    {
        return $query->where('is_active', true)->where('tracks_attendance', false);
    }

    /**
     * Foto profil.
     *
     * Urutannya: salinan lokal dulu, baru foto scan terakhir dari mesin.
     *
     * Salinan lokal didahulukan karena tautan foto mesin menunjuk ke S3 milik
     * Fingerspot dan bisa kedaluwarsa — daftar karyawan yang fotonya berubah
     * jadi kotak rusak beberapa bulan kemudian bukan hal yang mau diurus.
     * Foto scan tetap dipakai sebagai cadangan supaya karyawan baru langsung
     * punya wajah di layar tanpa menunggu ada yang mengunggahkan.
     */
    public function avatarUrl(): ?string
    {
        if ($this->photo_path && file_exists(public_path($this->photo_path))) {
            return asset($this->photo_path);
        }

        $pin = $this->pin_device ?? $this->devices->firstWhere('valid_to', null)?->pin;

        if ($pin && file_exists(public_path("avatars/pin_{$pin}.jpg"))) {
            return asset("avatars/pin_{$pin}.jpg");
        }

        return $this->latestPhotoUrl();
    }

    /** Inisial untuk dipakai kalau foto belum ada sama sekali. */
    public function initials(): string
    {
        $kata = preg_split('/\s+/', trim($this->name)) ?: [];

        // Nama seperti "21 Zafan" akan menghasilkan "2Z" kalau angkanya ikut,
        // jadi potongan yang bukan huruf dibuang lebih dulu.
        $huruf = array_values(array_filter(array_map(
            fn (string $k) => preg_match('/\p{L}/u', mb_substr($k, 0, 1)) ? mb_strtoupper(mb_substr($k, 0, 1)) : null,
            $kata,
        )));

        return implode('', array_slice($huruf, 0, 2)) ?: '?';
    }

    public function latestPhotoUrl(): ?string
    {
        return $this->attendanceLogs()
            ->whereNotNull('photo_url')
            ->where('photo_url', '!=', '')
            ->orderByDesc('scanned_at')
            ->value('photo_url');
    }
}
