<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Upaya mengirim satu notifikasi lewat satu kanal.
 *
 * Pola outbox: saat WhatsApp ditambahkan nanti, yang bertambah cuma driver -
 * tidak ada tabel yang perlu diubah.
 */
class NotificationDelivery extends Model
{
    protected $fillable = [
        'notification_id', 'channel', 'status', 'attempts', 'sent_at', 'failed_at', 'error',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'failed_at' => 'datetime'];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
