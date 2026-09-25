@extends('layouts.app')
@section('title', 'Slip Bonus')
@section('lebar', 'max-w-4xl')

@section('content')
@php
    $snap = $payslip->employee_snapshot ?? [];
    $rp = fn ($n) => 'Rp '.number_format((int) $n, 0, ',', '.');
    $durasi = fn ($menit) => \App\Support\Durasi::menit((int) $menit);
    $bonus = $payslip->items->where('category', 'bonus');
@endphp

<div class="mb-3 flex items-center justify-between print:hidden">
    <a href="{{ $kembali }}" class="text-sm text-slate-500 hover:underline">&larr; Kembali</a>
    <div class="flex gap-2">
        <a href="{{ $slipGaji }}" class="btn-netral">Slip Gaji</a>
        <button onclick="window.print()" class="btn-utama">Cetak / Simpan PDF</button>
    </div>
</div>

{{-- Dokumen sendiri, bukan bagian slip gaji: uang bonus diserahkan terpisah,
     jadi tanda terimanya juga terpisah. --}}
<div class="kartu bg-white p-8 text-slate-900 print:border-0 print:p-0 print:shadow-none">

    <div class="flex flex-col gap-3 border-b-2 border-slate-900 pb-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
        <div class="flex items-start gap-3">
            <x-logo-21 varian="hitam" class="mt-1 h-12 w-12 shrink-0" />
            <div>
                <div class="text-2xl font-bold leading-tight">21 KAFE</div>
                <div class="mt-0.5 text-[11px] leading-snug text-slate-600">
                    JL. LINGKAR, MUNCANG, KEC. BUMIAYU<br>
                    KAB. BREBES, JAWA TENGAH 52273<br>
                    <span class="mt-1 inline-block">21twentyonekafe@gmail.com</span>
                </div>
            </div>
        </div>
        <div class="text-left sm:text-right">
            <div class="text-2xl font-light text-amber-800">Slip Bonus</div>
            <div class="mt-1 text-[11px] uppercase tracking-wide text-slate-500">
                Periode: {{ $payslip->run->period->label() }}
            </div>
            <div class="font-mono text-[11px] text-slate-400">{{ $payslip->code }}-B</div>
        </div>
    </div>

    <div class="mt-5 grid gap-x-8 gap-y-2 text-[13px] sm:grid-cols-2">
        <div class="flex"><span class="w-28 font-semibold text-slate-600">Nama / PIN</span><span class="font-bold">: {{ $snap['name'] ?? $payslip->employee?->name }} ({{ $snap['pin'] ?? '—' }})</span></div>
        <div class="flex"><span class="w-28 font-semibold text-slate-600">ID Karyawan</span><span class="font-bold">: {{ $snap['employee_no'] ?? '—' }}</span></div>
        <div class="flex"><span class="w-28 font-semibold text-slate-600">Divisi</span><span class="font-bold">: {{ $snap['division'] ?? '—' }}</span></div>
        <div class="flex"><span class="w-28 font-semibold text-slate-600">Dibayar</span><span class="font-bold">: {{ $payslip->run->period->pay_date->translatedFormat('d M Y') }}</span></div>
    </div>

    {{-- Rincian sampai ke hari dan jamnya: bonus lembur paling sering
         dipertanyakan, dan pertanyaannya selalu "yang mana saja". --}}
    <div class="mt-6">
        <div class="rounded-t border border-slate-300 bg-slate-100 px-3 py-2 text-[11px] font-bold uppercase tracking-wide text-slate-800">
            Rincian Bonus
        </div>
        <div class="rounded-b border border-t-0 border-slate-300 px-4 py-3">
            @forelse ($bonus as $item)
                @php $rincian = $item->rule_snapshot['rincian'] ?? []; @endphp

                <div class="{{ $loop->last ? '' : 'mb-4 border-b border-slate-100 pb-4' }}">
                    <div class="flex items-baseline justify-between gap-3 text-[13px]">
                        <span class="font-semibold text-slate-800">
                            {{ $item->label }}
                            @if ($item->rate > 0 && $item->qty != 1)
                                <span class="text-[11px] font-normal text-slate-500">
                                    ({{ rtrim(rtrim(number_format($item->qty, 2, ',', '.'), '0'), ',') }} jam × {{ $rp($item->rate) }} per jam)
                                </span>
                            @endif
                        </span>
                        <span class="shrink-0 font-semibold tabular-nums">{{ $rp($item->amount) }}</span>
                    </div>

                    @if ($rincian)
                        <table class="mt-2 w-full text-[12px]">
                            <thead>
                                <tr class="text-left text-[10px] uppercase tracking-wide text-slate-500">
                                    <th class="pb-1 font-medium">Tanggal</th>
                                    <th class="pb-1 font-medium">Lama</th>
                                    <th class="pb-1 font-medium">Keterangan</th>
                                    <th class="pb-1 text-right font-medium">Jumlah</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rincian as $r)
                                    <tr class="text-slate-600">
                                        <td class="py-0.5">{{ \Illuminate\Support\Carbon::parse($r['date'])->translatedFormat('D, d M Y') }}</td>
                                        <td class="py-0.5">@isset($r['minutes']){{ $durasi($r['minutes']) }}@else—@endisset</td>
                                        <td class="py-0.5">{{ $r['note'] ?? ($r['rule'] ?? '—') }}</td>
                                        <td class="py-0.5 text-right tabular-nums">{{ $rp($r['amount'] ?? 0) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            @empty
                <div class="text-[13px] text-slate-400">Tidak ada bonus pada periode ini.</div>
            @endforelse
        </div>
    </div>

    <div class="mt-6 rounded bg-amber-900 px-5 py-4 text-white">
        <div class="text-[11px] uppercase tracking-[2px] text-amber-200">Total Bonus Diterima</div>
        <div class="text-3xl font-bold tabular-nums">{{ $rp($payslip->total_bonus) }}</div>
    </div>

    @if ($payslip->overtime_minutes > 0)
        <div class="mt-4 rounded border border-slate-300">
            <div class="border-b border-slate-300 bg-slate-50 px-3 py-2 text-[11px] font-bold uppercase tracking-wide text-slate-800">
                Rangkuman Lembur
            </div>
            <div class="grid gap-x-8 gap-y-2.5 px-4 py-3 text-[13px] sm:grid-cols-2">
                <div class="flex justify-between gap-3">
                    <span class="text-slate-600">Total jam lembur</span>
                    <span class="font-medium">{{ $durasi($payslip->overtime_minutes) }}</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span class="text-slate-600">Tarif per jam</span>
                    <span class="font-medium">{{ $rp($tarifJam) }}</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span class="text-slate-600">Hari kerja terjadwal</span>
                    <span class="font-medium">{{ $payslip->scheduled_days }} Hari</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span class="text-slate-600">Jumlah hari lembur</span>
                    <span class="font-medium">{{ $hariLembur }} Hari</span>
                </div>
            </div>
        </div>
    @endif

    <div class="mt-4 rounded border border-dashed border-slate-300 bg-slate-50 px-3 py-2 text-[10.5px] leading-relaxed text-slate-600">
        <div>&bull; Slip ini dokumen terpisah dari Slip Gaji periode yang sama.</div>
        <div>&bull; Bonus dibayarkan di luar Take Home Pay pada Slip Gaji.</div>
        @if ($payslip->overtime_minutes > 0)
            <div>&bull; Tarif lembur per jam = gaji pokok ÷ hari kerja terjadwal ÷ {{ $jamPerHari }} jam.</div>
        @endif
    </div>

    <div class="mt-10 flex flex-col gap-8 px-6 sm:flex-row sm:justify-between sm:gap-0">
        <div class="w-52 text-center">
            <div class="text-[13px] text-slate-600">Penerima,</div>
            <div class="mt-14 border-b border-slate-900 pb-1 text-[13px] font-bold">{{ $snap['name'] ?? $payslip->employee?->name }}</div>
            <div class="mt-1 text-[11px] text-slate-500">Karyawan</div>
        </div>
        <div class="w-52 text-center">
            <div class="text-[13px] text-slate-600">Bumiayu, {{ $payslip->run->period->pay_date->translatedFormat('d F Y') }}</div>
            <div class="mt-14 border-b border-slate-900 pb-1 text-[13px] font-bold">Manager</div>
            <div class="mt-1 text-[11px] text-slate-500">21 KAFE</div>
        </div>
    </div>
</div>
@endsection
