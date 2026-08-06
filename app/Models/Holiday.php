<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Libur yang berlaku untuk semua karyawan.
 */
class Holiday extends Model
{
    use HasFactory;

    protected $fillable = [
        'date',
        'name',
        'is_closed',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_closed' => 'boolean',
        ];
    }

    /**
     * Hanya libur yang benar-benar meliburkan orang. Tanggal merah yang kafenya
     * tetap buka tidak menghapus kewajiban masuk.
     */
    public function scopeClosing($query)
    {
        return $query->where('is_closed', true);
    }

    public static function closingOn(Carbon $date): ?self
    {
        return static::closing()->whereDate('date', $date)->first();
    }
}
