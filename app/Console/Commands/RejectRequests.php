<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Requests\RequestService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Tolak pengajuan dari terminal lewat RequestService::reject, yang sama
 * dengan tombol "Tolak" di web (saldo cuti yang ditahan dikembalikan,
 * pengaju diberi tahu, audit log tercatat).
 *
 * Alasan wajib, minimal 5 karakter, sama seperti di web.
 */
class RejectRequests extends Command
{
    protected $signature = 'pengajuan:tolak
                            {kode* : Kode pengajuan, boleh lebih dari satu}
                            {--alasan= : Alasan penolakan (wajib, min. 5 karakter)}';

    protected $description = 'Tolak pengajuan dari terminal, alasan wajib diisi';

    public function handle(RequestService $service): int
    {
        $alasan = trim((string) $this->option('alasan'));

        if (mb_strlen($alasan) < 5) {
            $this->error('Alasan penolakan wajib diisi (min. 5 karakter): --alasan="..."');

            return self::FAILURE;
        }

        $admin = User::where('role', 'admin')->first() ?? User::first();

        if ($admin === null) {
            $this->error('Belum ada pengguna admin untuk dicatat sebagai pemutus.');

            return self::FAILURE;
        }

        $gagal = 0;

        foreach ((array) $this->argument('kode') as $kode) {
            try {
                $pengajuan = DecideRequests::cari($kode);
                $service->reject($pengajuan, $admin, $alasan);
                $this->info("✓ {$pengajuan->code} ({$pengajuan->employee?->name}) ditolak.");
            } catch (RuntimeException $e) {
                $gagal++;
                $this->error("✗ {$kode}: ".$e->getMessage());
            }
        }

        return $gagal === 0 ? self::SUCCESS : self::FAILURE;
    }
}
