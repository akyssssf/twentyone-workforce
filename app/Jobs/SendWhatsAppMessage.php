<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Services\Notifications\WhatsApp\WhatsAppManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Pengiriman WhatsApp lewat antrean.
 *
 * Tidak pernah dikirim langsung di dalam permintaan HTTP. Gateway WhatsApp bisa
 * menggantung sampai belasan detik, dan admin yang menekan "Setujui" tidak
 * seharusnya menatap layar kosong menunggu pihak ketiga.
 *
 * Kegagalannya juga tidak boleh membatalkan approval — persetujuan sudah
 * tercatat di database; yang gagal cuma pemberitahuannya, dan itu tercatat di
 * notification_deliveries untuk ditindaklanjuti.
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $deliveryId,
        public string $nomor,
        public string $pesan,
    ) {
        $this->tries = (int) config('whatsapp.max_attempts', 3);
    }

    /** Tunggu makin lama tiap percobaan: 10 detik, 1 menit, 5 menit. */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(WhatsAppManager $whatsapp): void
    {
        $delivery = NotificationDelivery::find($this->deliveryId);

        if ($delivery === null || $delivery->status === 'sent') {
            return;
        }

        $delivery->increment('attempts');

        $whatsapp->send($this->nomor, $this->pesan);

        $delivery->update([
            'status' => 'sent',
            'sent_at' => now(),
            'error' => null,
        ]);
    }

    public function failed(Throwable $e): void
    {
        NotificationDelivery::where('id', $this->deliveryId)->update([
            'status' => 'failed',
            'failed_at' => now(),
            'error' => mb_substr($e->getMessage(), 0, 500),
        ]);
    }
}
