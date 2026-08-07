<?php

namespace App\Services\Notifications;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Notifications\WhatsApp\WhatsAppManager;
use App\Support\Settings;

/**
 * Pengirim pemberitahuan dengan pola outbox.
 *
 * `notifications` = apa yang perlu disampaikan.
 * `notification_deliveries` = upaya menyampaikannya, satu baris per kanal.
 *
 * Pemisahan itu yang membuat "sudah dikasih tahu belum?" bisa dijawab dari
 * data. Kalau pengiriman WhatsApp gagal, barisnya tetap ada dengan status
 * `failed` beserta alasannya — bukan hilang tanpa jejak.
 *
 * WhatsApp selalu lewat antrean, tidak pernah di dalam permintaan HTTP.
 */
class Notifier
{
    public function __construct(
        protected WhatsAppManager $whatsapp,
    ) {}

    public function send(
        User $user,
        string $title,
        ?string $body = null,
        ?string $link = null,
        array $payload = [],
        ?string $templateCode = null,
        ?string $whatsapp = null,
    ): Notification {
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

        // Pesan WhatsApp dikirim hanya kalau memang diminta pemanggil.
        // Tidak semua pemberitahuan layak masuk WhatsApp orang — yang terlalu
        // sering justru membuat yang penting ikut diabaikan.
        if ($whatsapp !== null) {
            $this->kirimWhatsApp($notification, $user->employee?->phone, $whatsapp);
        }

        return $notification;
    }

    /** Kirim ke semua akun manajemen. Dipakai saat ada pengajuan masuk. */
    public function sendToManagement(
        string $title,
        ?string $body = null,
        ?string $link = null,
        array $payload = [],
        ?string $whatsapp = null,
    ): void {
        User::query()
            ->active()
            ->whereIn('role', ['owner', 'manager', 'admin'])
            ->get()
            ->each(fn (User $user) => $this->send($user, $title, $body, $link, $payload, null, $whatsapp));

        // Nomor admin di setelan dipakai sebagai jalur tambahan: pemilik kafe
        // sering tidak punya akun karyawan, jadi tidak ada nomor yang menempel
        // pada akunnya.
        if ($whatsapp !== null) {
            $nomorAdmin = Settings::string('kontak.whatsapp_admin', (string) config('whatsapp.admin_number'));

            if (filled($nomorAdmin)) {
                $notification = Notification::create([
                    'user_id' => User::query()->active()->whereIn('role', ['owner', 'manager', 'admin'])->value('id'),
                    'title' => $title,
                    'body' => $body,
                    'link' => $link,
                ]);

                $this->kirimWhatsApp($notification, $nomorAdmin, $whatsapp);
            }
        }
    }

    /** Kirim langsung ke nomor karyawan, tanpa perlu dia punya akun. */
    public function whatsappToEmployee(Employee $employee, string $title, string $pesan): ?Notification
    {
        $notification = Notification::create([
            'user_id' => $employee->user?->id,
            'title' => $title,
            'body' => $pesan,
        ]);

        $this->kirimWhatsApp($notification, $employee->phone, $pesan);

        return $notification;
    }

    protected function kirimWhatsApp(Notification $notification, ?string $nomor, string $pesan): void
    {
        $delivery = NotificationDelivery::create([
            'notification_id' => $notification->id,
            'channel' => 'whatsapp',
            'status' => 'pending',
        ]);

        // Nomor kosong bukan kegagalan sistem, tapi tetap dicatat: inilah yang
        // menjelaskan kenapa seseorang tidak pernah menerima kode lemburnya,
        // dan jadi daftar kerja untuk melengkapi nomor karyawan.
        if (blank($nomor)) {
            $delivery->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error' => 'Nomor WhatsApp karyawan belum diisi.',
            ]);

            return;
        }

        SendWhatsAppMessage::dispatch($delivery->id, $nomor, $pesan);
    }
}
