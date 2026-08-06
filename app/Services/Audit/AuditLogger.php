<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Penulis jejak audit.
 *
 * Selalu lewat kelas ini, tidak pernah AuditLog::create() langsung, supaya
 * pembedaan aktor manusia vs sistem tidak pernah lupa diisi. Tanpa pembedaan
 * itu, log tenggelam oleh perubahan otomatis cron compute yang jalan tiap 15
 * menit, dan approval cuti yang penting jadi tidak terlihat.
 */
class AuditLogger
{
    public static function record(
        string $action,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        array $context = [],
    ): AuditLog {
        $user = Auth::user();

        return AuditLog::create([
            'actor_type' => $user ? 'user' : 'system',
            'actor_id' => $user?->id,

            // Nama disalin, bukan cuma direferensikan: akun bisa dihapus,
            // jejak siapa mengubah apa tidak boleh ikut putus.
            'actor_name' => $user?->name ?? 'Sistem',

            'action' => $action,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 255),
            'context' => $context ?: null,
        ]);
    }

    /** Perubahan yang dilakukan proses terjadwal, bukan manusia. */
    public static function system(string $action, ?Model $subject = null, array $context = []): AuditLog
    {
        return AuditLog::create([
            'actor_type' => 'system',
            'actor_name' => 'Sistem',
            'action' => $action,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'context' => $context ?: null,
        ]);
    }
}
