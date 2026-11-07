<?php

namespace App\Console\Commands;

use App\Enums\RequestType;
use App\Models\Request as Pengajuan;
use Illuminate\Console\Command;

/**
 * Rekap pengajuan untuk dibaca di terminal: apa yang diajukan, tanggal mana
 * yang tersentuh, siapa lawan tukarnya, dan menunggu siapa.
 */
class ListRequests extends Command
{
    protected $signature = 'pengajuan:daftar
                            {--status=menunggu : menunggu, disetujui, ditolak, batal, semua}
                            {--dari= : Hanya yang diajukan sejak tanggal ini (YYYY-MM-DD)}';

    protected $description = 'Rekap pengajuan (cuti, lembur, tukar shift/libur, koreksi) beserta rinciannya';

    public function handle(): int
    {
        $query = Pengajuan::query()
            ->with(['employee', 'substitute', 'leave.leaveType', 'overtime', 'correction',
                'swap.partner', 'swap.requesterAssignment.shift', 'swap.partnerAssignment.shift',
                'swap.requesterAssignment2.shift', 'swap.partnerAssignment2.shift'])
            ->orderBy('id');

        match ($this->option('status')) {
            'menunggu' => $query->pending(),
            'disetujui' => $query->where('status', 'approved'),
            'ditolak' => $query->where('status', 'rejected'),
            'batal' => $query->whereIn('status', ['cancelled', 'expired']),
            default => null,
        };

        if ($dari = $this->option('dari')) {
            $query->whereDate('submitted_at', '>=', $dari);
        }

        $rows = $query->get()->map(fn (Pengajuan $r) => [
            $r->code,
            $r->status->label(),
            $r->type->shortLabel(),
            ($r->employee?->pin_device ?? '?').' '.$r->employee?->name,
            $this->rincian($r),
            $r->substitute ? $r->substitute->name.($r->substitute_accepted_at ? ' ✓' : ($r->substitute_rejected_at ? ' ✗' : ' ?')) : '—',
            $r->submitted_at?->format('d/m H:i'),
            $r->expires_at?->format('d/m'),
        ]);

        if ($rows->isEmpty()) {
            $this->info('Tidak ada pengajuan.');

            return self::SUCCESS;
        }

        $this->table(['Kode', 'Status', 'Jenis', 'Pengaju', 'Rincian', 'Pengganti', 'Diajukan', 'Kedaluwarsa'], $rows->all());

        return self::SUCCESS;
    }

    protected function rincian(Pengajuan $r): string
    {
        $tgl = fn ($d) => $d?->translatedFormat('d M') ?? '?';

        return match ($r->type) {
            RequestType::Leave => sprintf('%s %s–%s (%s hari) %s', $r->leave?->leaveType?->name, $tgl($r->leave?->start_date), $tgl($r->leave?->end_date), $r->leave?->total_days, $r->leave?->reason ?? ''),
            RequestType::Overtime => sprintf('%s %s', $tgl($r->overtime?->work_date), $r->overtime?->reason ?? ''),
            RequestType::Correction => sprintf('%s %s | %s', $tgl($r->correction?->work_date), str_replace('_', ' ', $r->correction?->correction_type ?? ''), $r->correction?->reason ?? ''),
            RequestType::Swap => $this->rincianTukar($r, $tgl),
        };
    }

    protected function rincianTukar(Pengajuan $r, callable $tgl): string
    {
        $s = $r->swap;

        if ($s === null) {
            return '(detail tukar hilang)';
        }

        $a = fn ($baris) => $baris ? $tgl($baris->work_date).' '.($baris->shift?->name ?? 'libur') : '?';
        $rekan = $s->partner?->name ?? '(rekan kosong)';

        if ($s->isTukarLibur()) {
            return sprintf('TUKAR LIBUR dgn %s: libur %s ↔ libur %s', $rekan, $a($s->requesterAssignment), $a($s->partnerAssignment2));
        }

        return sprintf('TUKAR SHIFT dgn %s: %s', $rekan, $a($s->requesterAssignment));
    }
}
