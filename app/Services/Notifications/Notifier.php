<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;

/**
 * Pengirim notifikasi dengan pola outbox.
 *
 * `notifications` = apa yang perlu disampaikan.
 * `notification_deliveries` = upaya menyampaikannya per kanal.
 *
 * Saat ini hanya kanal `database` (lonceng di aplikasi) yang benar-benar
 * mengirim. Struktur ini sudah siap untuk WhatsApp: yang bertambah nanti cuma
 * satu driver, tidak ada tabel yang perlu diubah (BR-30).
 */
class Notifier
{
    public function send(User $user, string $title, ?string $body = null, ?string $link = null, array $payload = [], ?string $templateCode = null): Notification
    {
        $notification = Notification::create([
            'user_id' => $user->id,
            'template_code' => $templateCode,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'payload' => $payload ?: null,
        ]);

        // Kanal database dianggap terkirim seketika: begitu barisnya ada,
        // lonceng di aplikasi sudah menampilkannya.
        NotificationDelivery::create([
            'notification_id' => $notification->id,
            'channel' => 'database',
            'status' => 'sent',
            'attempts' => 1,
            'sent_at' => now(),
        ]);

        return $notification;
    }

    /** Kirim ke semua manager dan owner. Dipakai saat ada pengajuan masuk. */
    public function sendToManagement(string $title, ?string $body = null, ?string $link = null, array $payload = []): void
    {
        User::query()
            ->active()
            ->whereIn('role', ['owner', 'manager'])
            ->get()
            ->each(fn (User $user) => $this->send($user, $title, $body, $link, $payload));
    }
}
