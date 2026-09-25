@extends('layouts.app')
@section('title', 'Slip Gaji')
@section('lebar', 'max-w-4xl')

@section('content')
@php
    $snap = $payslip->employee_snapshot ?? [];
    $rp = fn ($n) => 'Rp '.number_format((int) $n, 0, ',', '.');
    $durasi = fn ($menit) => \App\Support\Durasi::menit((int) $menit);

    $pendapatan = $payslip->items->where('category', 'earning');
    $potongan = $payslip->items->whereIn('category', ['deduction', 'statutory']);
    $bonus = $payslip->items->where('category', 'bonus');
    $info = $payslip->items->where('category', 'info');

    $toleransi = \App\Support\Settings::int('attendance.late_tolerance_minutes');
    $cuti = max(0, $payslip->leave_days - $payslip->permit_days - $payslip->sick_days);
@endphp

<div class="mb-3 flex items-center justify-between print:hidden">
    <a href="{{ $kembali }}" class="text-sm text-slate-500 hover:underline">&larr; Kembali</a>
    <button onclick="window.print()" class="btn-netral">Cetak / Simpan PDF</button>
</div>

{{-- Satu lembar A4: kop, identitas, tiga kotak (pendapatan, potongan,
     bonus), take home pay, rangkuman kehadiran, tanda tangan. --}}
<div class="kartu bg-white p-8 text-slate-900 print:border-0 print:p-0 print:shadow-none">

    {{-- Kop surat --}}
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
            <div class="text-2xl font-light text-slate-800">Slip Gaji @if ($bonus->isNotEmpty())&amp; Bonus @endif</div>
            <div class="mt-1 text-[11px] uppercase tracking-wide text-slate-500">
                Periode: {{ $payslip->run->period->label() }}
            </div>
            <div class="font-mono text-[11px] text-slate-400">{{ $payslip->code }}</div>
        </div>
    </div>

    {{-- Identitas: disalin saat slip dibuat, tidak ikut berubah kalau orangnya
         pindah divisi bulan depan. --}}
    <div class="mt-5 grid gap-x-8 gap-y-2 text-[13px] sm:grid-cols-2">
        <div class="flex"><span class="w-28 font-semibold text-slate-600">Nama / PIN</span><span class="font-bold">: {{ $snap['name'] ?? $payslip->employee?->name }} ({{ $snap['pin'] ?? '—' }})</span></div>
        <div class="flex"><span class="w-28 font-semibold text-slate-600">ID Karyawan</span><span class="font-bold">: {{ $snap['employee_no'] ?? '—' }}</span></div>
        <div class="flex"><span class="w-28 font-semibold text-slate-600">Divisi</span><span class="font-bold">: {{ $snap['division'] ?? '—' }}</span></div>
        <div class="flex"><span class="w-28 font-semibold text-slate-600">Dibayar</span><span class="font-bold">: {{ $payslip->run->period->pay_date->translatedFormat('d M Y') }}</span></div>
    </div>

    {{-- Pendapatan · Potongan · Bonus --}}
    <div class="mt-6 grid gap-4 {{ $bonus->isNotEmpty() ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }}">
        @foreach ([
            ['Pendapatan', $pendapatan, 'text-slate-900', 'bg-emerald-50 border-emerald-200 text-emerald-800', $payslip->total_earning, null],
            ['Potongan', $potongan, 'text-red-700', 'bg-red-50 border-red-200 text-red-800', $payslip->total_deduction + $payslip->total_statutory, null],
            ['Bonus', $bonus, 'text-emerald-700', 'bg-emerald-600 border-emerald-600 text-white', $payslip->total_bonus, 'Dibayar terpisah, di luar take home pay'],
        ] as [$judul, $baris, $warna, $totalKelas, $total, $catatan])
            @continue($judul === 'Bonus' && $bonus->isEmpty())

            <div class="flex flex-col">
                <div class="rounded-t border border-slate-300 bg-slate-100 px-3 py-2 text-[11px] font-bold uppercase tracking-wide text-slate-800">
                    {{ $judul }}
                </div>
                <div class="min-h-[96px] grow rounded-b border border-t-0 border-slate-300 px-3 py-3">
                    @forelse ($baris as $item)
                        @php $rincian = $item->rule_snapshot['rincian'] ?? []; @endphp
                        <div class="mb-2 flex justify-between gap-3 text-[13px] last:mb-0">
                            <span class="text-slate-700">
                                {{ $item->label }}
                                @if ($rincian)
                                    <span class="mt-0.5 block text-[10px] leading-snug text-slate-500">
                                        @foreach ($rincian as $r)
                                            {{ \Illuminate\Support\Carbon::parse($r['date'])->translatedFormat('d M') }}@isset($r['minutes']) {{ $durasi($r['minutes']) }}@endisset {{ number_format($r['amount'] ?? 0, 0, ',', '.') }}{{ $loop->last ? '' : ' · ' }}
                                        @endforeach
                                    </span>
                                @elseif (! empty($item->rule_snapshot['kasbon']['alasan']) && $item->rule_snapshot['kasbon']['alasan'] !== 'Kasbon')
                                    <span class="mt-0.5 block text-[10px] text-slate-500">{{ $item->rule_snapshot['kasbon']['alasan'] }}</span>
                                @endif
                            </span>
                            <span class="shrink-0 font-medium tabular-nums {{ $judul === 'Potongan' ? 'text-red-600' : '' }}">
                                {{ $rp($item->amount) }}
                            </span>
                        </div>
                    @empty
                        <div class="text-[13px] text-slate-400">—</div>
                    @endforelse
                </div>
                <div class="mt-2 flex items-center justify-between rounded border px-3 py-2 text-[13px] font-bold {{ $totalKelas }}">
                    <span>Total {{ $judul }}</span>
                    <span class="tabular-nums">{{ $rp($total) }}</span>
                </div>
                @if ($catatan)
                    <div class="mt-1 text-[10px] leading-snug text-slate-500">{{ $catatan }}</div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Take home pay: gaji saja. Bonus sengaja tidak dijumlahkan ke sini —
         uangnya diserahkan terpisah, jadi angka ini harus sama dengan yang
         benar-benar diterima sebagai gaji. --}}
    <div class="mt-6 rounded bg-slate-900 px-5 py-4 text-white">
        <div class="text-[11px] uppercase tracking-[2px] text-slate-400">Total Diterima (Take Home Pay)</div>
        <div class="text-3xl font-bold tabular-nums">{{ $rp($payslip->take_home_pay) }}</div>
    </div>

    {{-- Rangkuman kehadiran --}}
    <div class="mt-6 rounded border border-slate-300">
        <div class="border-b border-slate-300 bg-slate-50 px-3 py-2 text-[11px] font-bold uppercase tracking-wide text-slate-800">
            Rangkuman Informasi Kehadiran
        </div>
        <div class="grid gap-x-8 gap-y-2.5 px-4 py-3 text-[13px] sm:grid-cols-2">
            @foreach ([
                'Kehadiran' => $payslip->present_days . ' Hari',
                'Terlambat (>' . $toleransi . ' mnt)' => $payslip->late_count . 'x (' . $payslip->late_minutes . ' mnt)',
                'Izin' => $payslip->permit_days . ' Hari',
                'Sakit' => $payslip->sick_days . ' Hari',
                'Alpha (Tanpa Keterangan)' => $payslip->absent_days . ' Hari',
                'Total Lembur' => $durasi($payslip->overtime_minutes) . ($payslip->overtime_minutes > 0 ? ' *' : ''),
            ] as $label => $nilai)
                <div class="flex justify-between gap-3">
                    <span class="text-slate-600">{{ $label }}</span>
                    <span class="font-medium">{{ $nilai }}</span>
                </div>
            @endforeach

            @if ($cuti > 0)
                <div class="flex justify-between gap-3">
                    <span class="text-slate-600">Cuti</span>
                    <span class="font-medium">{{ $cuti }} Hari</span>
                </div>
            @endif
        </div>
    </div>

    {{-- Keterangan aturan: angka di atas harus bisa dihitung ulang sendiri
         oleh yang menerimanya. --}}
    <div class="mt-4 rounded border border-dashed border-slate-300 bg-slate-50 px-3 py-2 text-[10.5px] leading-relaxed text-slate-600">
        <div class="font-bold text-slate-700">Keterangan</div>
        @unless ($info->contains(fn ($i) => str_contains($i->label, 'Toleransi telat')))
            <div>&bull; Keterlambatan sampai dengan {{ $toleransi }} menit ditoleransi (tidak dipotong).</div>
        @endunless
        @foreach ($info as $item)
            <div>&bull; {{ $item->label }}@if ($item->rate > 0): {{ $rp($item->rate) }}@endif</div>
        @endforeach
        @if ($payslip->overtime_minutes > 0)
            <div>* Jam lembur dibayarkan terpisah dan tidak termasuk dalam Take Home Pay di atas.</div>
        @endif
    </div>

    {{-- Tanda tangan --}}
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

    <p class="mt-6 text-[10px] text-slate-400 print:hidden">
        Slip ini dihasilkan sistem pada {{ $payslip->created_at?->translatedFormat('d M Y H:i') }}.
        Angka di sini dibekukan saat payroll dihitung — perubahan tarif setelahnya tidak mengubah slip ini.
    </p>
</div>
@endsection
