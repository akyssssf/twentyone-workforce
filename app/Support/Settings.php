<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Pembaca setelan operasional.
 *
 * Setelan disimpan sebagai key-value di database, bukan di config file, supaya
 * manager bisa mengubahnya dari UI tanpa deploy. Yang hilang dari cara ini
 * adalah type-safety database — ditebus di sini dengan casting terpusat, jadi
 * pemanggil selalu menerima tipe yang benar tanpa perlu ingat mengonversi.
 */
class Settings
{
    protected const CACHE_KEY = 'settings.branch.';

    /** Nilai bawaan kalau baris setelannya belum ada di database. */
    protected const DEFAULTS = [
        'roster.min_rest_hours' => 10,
        'roster.max_consecutive_days' => 6,
        'roster.target_off_days_per_week' => 1,
        'roster.warn_double_shift' => true,
        'attendance.check_in_out_strategy' => 'earliest_latest',
        'attendance.close_day_hour' => 6,
        'overtime.allow_backdated' => true,
        'payroll.period_start_day' => 21,
        'payroll.pay_day' => 21,
        'payroll.working_days_basis' => 'scheduled',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = static::all();

        return $all[$key] ?? static::DEFAULTS[$key] ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) static::get($key, $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return (bool) static::get($key, $default);
    }

    public static function string(string $key, string $default = ''): string
    {
        return (string) static::get($key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(?int $branchId = null): array
    {
        $branchId ??= Branch::current()->id;

        return Cache::remember(static::CACHE_KEY . $branchId, 3600, function () use ($branchId) {
            return Setting::query()
                ->where('branch_id', $branchId)
                ->pluck('value', 'key')
                ->all();
        });
    }

    public static function put(string $key, mixed $value, ?int $branchId = null): void
    {
        $branchId ??= Branch::current()->id;

        Setting::updateOrCreate(
            ['branch_id' => $branchId, 'key' => $key],
            ['value' => $value, 'group' => explode('.', $key)[0]],
        );

        static::flush($branchId);
    }

    public static function flush(?int $branchId = null): void
    {
        $branchId ??= Branch::current()->id;

        Cache::forget(static::CACHE_KEY . $branchId);
    }
}
