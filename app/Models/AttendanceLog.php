<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class AttendanceLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'cloud_id',
        'pin',
        'scanned_at',
        'scan_minute',
        'verify_mode',
        'status_scan',
        'io_mode',
        'photo_url',
        'source',
        'employee_id',
        'resolved_at',
        'import_batch_id',
        'payload',
        'device_callback_id',
    ];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
            'scan_minute' => 'datetime',
            'verify_mode' => 'integer',
            'status_scan' => 'integer',
            'io_mode' => 'integer',
            'payload' => 'array',
        ];
    }

    /**
     * Karyawan pemilik scan ini.
     *
     * Hasil pencocokan PIN lewat employee_devices yang berlaku pada TANGGAL
     * SCAN — bukan pemetaan hari ini. Bisa null kalau PIN-nya belum terdaftar;
     * scan-nya tetap disimpan supaya tidak ada data yang hilang.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function deviceCallback(): BelongsTo
    {
        return $this->belongsTo(DeviceCallback::class);
    }

    /**
     * Cocokkan scan ke karyawan saat baris dibuat.
     *
     * Ditaruh di model, bukan di parser, supaya SEMUA jalur masuk ikut
     * terlayani tanpa perlu diingat satu per satu: webhook realtime, cron
     * get_attlog, unggah manual, dan seeder. Jalur yang lupa memanggil
     * resolver adalah jalur yang scan-nya diam-diam tidak masuk rekap siapa
     * pun — kegagalan yang tidak menimbulkan error dan baru ketahuan saat
     * gajian.
     */
    protected static function booted(): void
    {
        static::creating(function (self $log) {
            if ($log->employee_id !== null || blank($log->pin)) {
                return;
            }

            $tanggal = $log->scanned_at instanceof Carbon
                ? $log->scanned_at
                : Carbon::parse($log->scanned_at);

            $employeeId = EmployeeDevice::resolveEmployeeId($log->pin, $tanggal, $log->cloud_id)
                // Mesin kedua bisa punya SN berbeda dari yang tercatat di
                // pemetaan. Kalau pencarian ketat tidak ketemu, coba tanpa
                // menyaring SN — lebih baik tercocokkan daripada menggantung.
                ?? EmployeeDevice::resolveEmployeeId($log->pin, $tanggal);

            if ($employeeId !== null) {
                $log->employee_id = $employeeId;
                $log->resolved_at = now();
            }
        });
    }

    /** Scan yang PIN-nya tidak dikenali pemetaan mana pun. Antrian rekonsiliasi. */
    public function scopeUnresolved($query)
    {
        return $query->whereNull('employee_id');
    }

    /**
     * Label kode verifikasi. Balik ke angka mentah kalau kodenya di luar
     * kamus, karena mesin non-Fingerspot bebas pakai kode sendiri.
     */
    public function verifyModeLabel(): ?string
    {
        return config("fingerspot.verify_modes.{$this->verify_mode}", (string) $this->verify_mode);
    }

    public function statusScanLabel(): ?string
    {
        return config("fingerspot.status_scans.{$this->status_scan}", (string) $this->status_scan);
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('scanned_at', $date);
    }

    public function scopeFromWebhook($query)
    {
        return $query->where('source', 'webhook');
    }

    public function scopeFromSync($query)
    {
        return $query->where('source', 'sync');
    }
}
