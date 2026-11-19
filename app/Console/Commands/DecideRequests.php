<?php

namespace App\Console\Commands;

use App\Enums\RequestStatus;
use App\Models\Request as Pengajuan;
use App\Models\User;
use App\Services\Requests\RequestService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Setujui pengajuan dari terminal, jalur yang sama persis dengan tombol
 * "Setujui" di web (RequestService::approve), supaya efek ke roster, saldo
 * cuti, lembur, rekap absensi, notifikasi, dan audit log tidak berbeda.
 *
 * Kode boleh ditulis lengkap (REQ-2026-10-0028) atau ekornya saja (10-0028)
 * selama tidak ambigu. Beberapa kode sekaligus diproses satu per satu: yang
 * gagal dilaporkan, yang lain tetap jalan.
 */
class DecideRequests extends Command
{
    protected $signature = 'pengajuan:setujui
                            {kode* : Kode pengajuan, boleh lebih dari satu}
                            {--catatan= : Catatan persetujuan (opsional)}
                            {--konfirmasi-pengganti : Tandai pengganti sudah setuju (sudah dihubungi langsung) kalau pengajuan masih menunggu pengganti}';

    protected $description = 'Setujui pengajuan (cuti, lembur, tukar shift/libur, koreksi) dari terminal';

    public function handle(RequestService $service): int
    {
        $admin = User::where('role', 'admin')->first() ?? User::first();

        if ($admin === null) {
            $this->error('Belum ada pengguna admin untuk dicatat sebagai pemutus.');

            return self::FAILURE;
        }

        $gagal = 0;

        foreach ((array) $this->argument('kode') as $kode) {
            try {
                $pengajuan = self::cari($kode);

                if ($pengajuan->status === RequestStatus::PendingPeer && $this->option('konfirmasi-pengganti')) {
                    $service->confirmSubstituteByAdmin($pengajuan, $admin);
                }

                $service->approve($pengajuan, $admin, $this->option('catatan') ?: null);
                $this->info("✓ {$pengajuan->code} ({$pengajuan->employee?->name}) disetujui.");
            } catch (RuntimeException $e) {
                $gagal++;
                $this->error("✗ {$kode}: ".$e->getMessage());
            }
        }

        return $gagal === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Cari pengajuan dari kode lengkap atau ekornya; ambigu atau tidak ada = galat. */
    public static function cari(string $kode): Pengajuan
    {
        $kode = trim($kode);
        $cocok = Pengajuan::with('employee')->where('code', $kode)->get();

        if ($cocok->isEmpty()) {
            $cocok = Pengajuan::with('employee')->where('code', 'like', '%-'.ltrim($kode, '-'))->get();
        }

        if ($cocok->count() > 1) {
            throw new RuntimeException("Kode \"{$kode}\" cocok dengan ".$cocok->count().' pengajuan: '.$cocok->pluck('code')->implode(', ').'. Tulis kode lengkap.');
        }

        return $cocok->first() ?? throw new RuntimeException("Pengajuan \"{$kode}\" tidak ditemukan. Lihat daftar: php artisan pengajuan:daftar --status=semua");
    }
}
