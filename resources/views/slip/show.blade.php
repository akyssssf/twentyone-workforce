@extends('layouts.app')
@section('title', 'Slip Gaji')
@section('lebar', 'max-w-4xl')

@section('judul-berkas', \App\Support\NamaBerkasSlip::untuk($payslip, 'SLIP_GAJI'))

@section('content')
@php
    $v = \App\Support\SlipView::data($payslip);
    $rp = fn ($n) => 'Rp '.number_format((int) $n, 0, ',', '.');
@endphp

<div class="mb-3 flex items-center justify-between print:hidden">
    <a href="{{ $kembali }}" class="text-sm text-slate-500 hover:underline">&larr; Kembali</a>
    <div class="flex gap-2">
        @if ($v['adaBonus'] && ! empty($slipBonus))
            <a href="{{ $slipBonus }}" class="btn border border-amber-300 bg-white text-amber-800 hover:bg-amber-50">
                Slip Bonus ({{ $rp($v['totalBonus']) }})
            </a>
        @endif
        <button onclick="window.print()" class="btn-netral">Cetak / Simpan PDF</button>
    </div>
</div>

{{-- Satu lembar A4: kop, identitas, dua kotak (pendapatan, potongan), take home
     pay, rangkuman kehadiran, tanda tangan. Bonus punya dokumennya sendiri. --}}
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
            <div class="text-2xl font-light text-slate-800">Slip Gaji</div>
            <div class="mt-1 text-[11px] uppercase tracking-wide text-slate-500">Periode: {{ $v['periode'] }}</div>
            <div class="font-mono text-[11px] text-slate-400">{{ $payslip->code }}</div>
        </div>
    </div>

    <div class="mt-5 grid gap-x-8 gap-y-2 text-[13px] sm:grid-cols-2">
        <div class="flex"><span class="w-28 font-semibold text-slate-600">Nama / PIN</span><span class="font-bold">: {{ $v['nama'] }} ({{ $v['pin'] }})</span></div>
        <div class="flex"><span class="w-28 font-semibold text-slate-600">ID Karyawan</span><span class="font-bold">: {{ $v['noInduk'] }}</span></div>
        <div class="flex"><span class="w-28 font-semibold text-slate-600">Divisi</span><span class="font-bold">: {{ $v['divisi'] }}</span></div>
        <div class="flex"><span class="w-28 font-semibold text-slate-600">Dibayar</span><span class="font-bold">: {{ $v['dibayar'] }}</span></div>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-2">
        @foreach ([
            ['Pendapatan', $v['pendapatan'], 'bg-emerald-50 border-emerald-200 text-emerald-800', $v['totalPendapatan'], false],
            ['Potongan', $v['potongan'], 'bg-red-50 border-red-200 text-red-800', $v['totalPotongan'], true],
        ] as [$judul, $baris, $totalKelas, $total, $merah])
            <div class="flex flex-col">
                <div class="cetak-warna rounded-t border border-slate-300 bg-slate-100 px-3 py-2 text-[11px] font-bold uppercase tracking-wide text-slate-800">{{ $judul }}</div>
                <div class="min-h-[120px] grow rounded-b border border-t-0 border-slate-300 px-3 py-3">
                    @forelse ($baris as $b)
                        <div class="mb-3 last:mb-0">
                            <div class="flex justify-between gap-3 text-[13px]">
                                <span class="text-slate-700">{{ $b['label'] }}</span>
                                <span class="shrink-0 font-semibold tabular-nums {{ $merah ? 'text-red-600' : '' }}">{{ $rp($b['jumlah']) }}</span>
                            </div>
                            @if ($b['rumus'])
                                <div class="text-[10px] text-slate-400">{{ $b['rumus'] }}</div>
                            @endif

                            {{-- Satu baris pendek per kejadian: tanggal · lama ....... rupiah.
                                 Dulu satu kalimat panjang yang harus dibaca menyamping. --}}
                            @if ($b['detail'])
                                <ul class="mt-1 space-y-0.5 border-l-2 border-slate-100 pl-2">
                                    @foreach ($b['detail'] as $d)
                                        <li class="flex justify-between gap-2 text-[10.5px] text-slate-500">
                                            <span>{{ $d['tanggal'] }}@if ($d['lama']) · {{ $d['lama'] }}@endif</span>
                                            @if ($d['jumlah'] !== null)<span class="tabular-nums">{{ number_format($d['jumlah'], 0, ',', '.') }}</span>@endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @empty
                        <div class="text-[13px] text-slate-400">Tidak ada.</div>
                    @endforelse
                </div>
                <div class="cetak-warna mt-2 flex items-center justify-between rounded border px-3 py-2 text-[13px] font-bold {{ $totalKelas }}">
                    <span>Total {{ $judul }}</span>
                    <span class="tabular-nums">{{ $rp($total) }}</span>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Take home pay: gaji saja. Bonus sengaja tidak dijumlahkan ke sini —
         uangnya diserahkan terpisah. --}}
    <div class="cetak-warna mt-6 rounded bg-slate-900 px-5 py-4 text-white">
        <div class="text-[11px] uppercase tracking-[2px] text-slate-400">Total Diterima (Take Home Pay)</div>
        <div class="text-3xl font-bold tabular-nums">{{ $rp($v['thp']) }}</div>
    </div>

    <div class="mt-6 rounded border border-slate-300">
        <div class="cetak-warna border-b border-slate-300 bg-slate-50 px-3 py-2 text-[11px] font-bold uppercase tracking-wide text-slate-800">Rangkuman Informasi Kehadiran</div>
        <div class="grid gap-x-8 gap-y-2.5 px-4 py-3 text-[13px] sm:grid-cols-2">
            @foreach ($v['kehadiran'] as $label => $nilai)
                <div class="flex justify-between gap-3">
                    <span class="text-slate-600">{{ $label }}</span>
                    <span class="font-medium">{{ $nilai }}</span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="mt-4 rounded border border-dashed border-slate-300 bg-slate-50 px-3 py-2 text-[10.5px] leading-relaxed text-slate-600">
        <div class="font-bold text-slate-700">Keterangan</div>
        @foreach ($v['keterangan'] as $k)<div>&bull; {{ $k }}</div>@endforeach
        @if ($v['adaBonus'])<div>&bull; Bonus {{ $rp($v['totalBonus']) }} dicetak pada <strong>Slip Bonus</strong> terpisah.</div>@endif
    </div>

    <div class="mt-10 flex flex-col gap-8 px-6 sm:flex-row sm:justify-between sm:gap-0">
        <div class="w-52 text-center">
            <div class="text-[13px] text-slate-600">Penerima,</div>
            <div class="mt-14 border-b border-slate-900 pb-1 text-[13px] font-bold">{{ $v['nama'] }}</div>
            <div class="mt-1 text-[11px] text-slate-500">Karyawan</div>
        </div>
        <div class="w-52 text-center">
            <div class="text-[13px] text-slate-600">Bumiayu, {{ $v['dibayarPanjang'] }}</div>
            <div class="mt-14 border-b border-slate-900 pb-1 text-[13px] font-bold">Manager</div>
            <div class="mt-1 text-[11px] text-slate-500">21 KAFE</div>
        </div>
    </div>

    <p class="mt-6 text-[10px] text-slate-400 print:hidden">
        Slip ini dihasilkan sistem pada {{ $payslip->created_at?->translatedFormat('d M Y H:i') }}.
        Angka di sini dibekukan saat payroll dihitung — perubahan tarif setelahnya tidak mengubah slip ini.
    </p>
</div>
@endsection
