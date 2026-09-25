@extends('layouts.app')
@section('title', 'Slip Gaji')

@section('content')
@php
    $snap = $payslip->employee_snapshot ?? [];
    $rp = fn ($n) => number_format((int) $n, 0, ',', '.');
    $tgl = fn ($ymd) => \Illuminate\Support\Carbon::parse($ymd)->translatedFormat('D, d M');
    $durasi = fn ($menit) => \App\Support\Durasi::menit((int) $menit);
    $info = $payslip->items->where('category', 'info');
    $bonus = $payslip->items->where('category', 'bonus');
@endphp

<div class="mx-auto max-w-3xl">
    <div class="mb-3 flex items-center justify-between print:hidden">
        <a href="{{ $kembali }}" class="text-sm text-slate-500 hover:underline">&larr; Kembali</a>
        <button onclick="window.print()" class="btn-netral">
            Cetak / Simpan PDF
        </button>
    </div>

    <div class="kartu p-6 print:border-0">
        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-4">
            <div>
                <h1 class="text-lg font-semibold">Slip Gaji</h1>
                <p class="text-sm text-slate-500">{{ config('app.name') }}</p>
            </div>
            <div class="text-right text-sm">
                <div class="font-mono text-xs text-slate-500">{{ $payslip->code }}</div>
                <div>Periode {{ $payslip->run->period->label() }}</div>
                <div class="text-slate-500">Dibayar {{ $payslip->run->period->pay_date->translatedFormat('d M Y') }}</div>
            </div>
        </div>

        {{-- Data karyawan diambil dari snapshot, bukan dari tabel employees.
             Slip yang sudah terbit tidak boleh berubah isinya hanya karena
             yang bersangkutan pindah divisi bulan depan. --}}
        <dl class="grid grid-cols-2 gap-x-6 gap-y-2 border-b border-slate-200 py-4 text-sm">
            <div class="flex"><dt class="w-28 text-slate-500">Nama</dt><dd class="font-medium">{{ $snap['name'] ?? $payslip->employee?->name }}</dd></div>
            <div class="flex"><dt class="w-28 text-slate-500">No. Induk</dt><dd>{{ $snap['employee_no'] ?? '—' }}</dd></div>
            <div class="flex"><dt class="w-28 text-slate-500">Divisi</dt><dd>{{ $snap['division'] ?? '—' }}</dd></div>
            <div class="flex"><dt class="w-28 text-slate-500">PIN mesin</dt><dd>{{ $snap['pin'] ?? '—' }}</dd></div>
        </dl>

        <div class="grid grid-cols-3 gap-3 border-b border-slate-200 py-4 text-sm sm:grid-cols-6">
            @foreach ([
                'Hari kerja' => $payslip->scheduled_days,
                'Hadir' => $payslip->present_days,
                'Alpha' => $payslip->absent_days,
                'Cuti/Izin' => $payslip->leave_days,
                'Telat' => $payslip->late_count . 'x',
                'Lembur' => $durasi($payslip->overtime_minutes),
            ] as $label => $nilai)
                <div>
                    <div class="text-xs text-slate-500">{{ $label }}</div>
                    <div class="font-semibold">{{ $nilai }}</div>
                </div>
            @endforeach
        </div>

        @foreach ([
            'earning' => ['Pendapatan', 'text-emerald-700'],
            'deduction' => ['Potongan', 'text-red-700'],
            'statutory' => ['BPJS & Potongan Wajib', 'text-red-700'],
        ] as $kategori => [$judul, $warna])
            @php $items = $payslip->items->where('category', $kategori); @endphp
            @if ($items->isNotEmpty())
                <div class="py-4">
                    <h2 class="mb-2 text-sm font-semibold {{ $warna }}">{{ $judul }}</h2>
                    @include('slip._rincian', ['items' => $items])
                </div>
            @endif
        @endforeach

        <div class="mt-2 flex items-center justify-between rounded-lg bg-slate-900 px-4 py-3 text-white">
            <span class="font-medium">Take Home Pay</span>
            <span class="text-xl font-semibold tabular-nums">Rp {{ $rp($payslip->take_home_pay) }}</span>
        </div>

        {{-- Dasar perhitungan: angka yang dipakai, supaya slip bisa dicek
             ulang dengan kalkulator. Bukan uang, jadi tidak ikut subtotal. --}}
        @if ($info->isNotEmpty())
            <div class="mt-4 rounded-lg bg-slate-50 px-4 py-3 text-xs text-slate-600">
                <h2 class="mb-1 font-semibold text-slate-700">Dasar perhitungan</h2>
                <ul class="space-y-0.5">
                    @foreach ($info as $item)
                        @php $rincian = $item->rule_snapshot['rincian'] ?? []; @endphp
                        <li class="flex justify-between gap-3">
                            <span>{{ $item->label }}</span>
                            @if ($item->rate > 0)
                                <span class="tabular-nums">Rp {{ $rp($item->rate) }}</span>
                            @endif
                        </li>
                        @if ($rincian)
                            <li class="pl-3 text-slate-400">
                                @foreach ($rincian as $r)
                                    {{ $tgl($r['date']) }}@isset($r['seconds']) ({{ \App\Support\Durasi::detik((int) $r['seconds']) }})@endisset{{ $loop->last ? '' : ', ' }}
                                @endforeach
                            </li>
                        @endif
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($bonus->isNotEmpty())
            <p class="mt-4 text-xs text-slate-500">
                Bonus {{ $rp($payslip->total_bonus) }} dibayar terpisah dari gaji — rinciannya di lembar berikutnya, tidak termasuk take home pay di atas.
            </p>
        @endif

        <p class="mt-4 text-xs text-slate-400">
            Slip ini dihasilkan sistem pada {{ $payslip->created_at?->translatedFormat('d M Y H:i') }}.
            Angka di sini dibekukan saat payroll dihitung — perubahan tarif setelahnya tidak mengubah slip ini.
        </p>
    </div>

    {{-- Slip bonus: lembar sendiri, karena uangnya memang diserahkan
         terpisah. break-before-page supaya sekali Cetak menghasilkan dua
         lembar yang bisa dibagikan sendiri-sendiri. --}}
    @if ($bonus->isNotEmpty())
        <div class="kartu mt-6 p-6 print:mt-0 print:border-0 print:break-before-page">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-4">
                <div>
                    <h1 class="text-lg font-semibold">Slip Bonus</h1>
                    <p class="text-sm text-slate-500">{{ config('app.name') }} &middot; dibayar terpisah dari gaji</p>
                </div>
                <div class="text-right text-sm">
                    <div class="font-mono text-xs text-slate-500">{{ $payslip->code }}-B</div>
                    <div>Periode {{ $payslip->run->period->label() }}</div>
                    <div class="text-slate-500">Dibayar {{ $payslip->run->period->pay_date->translatedFormat('d M Y') }}</div>
                </div>
            </div>

            <dl class="grid grid-cols-2 gap-x-6 gap-y-2 border-b border-slate-200 py-4 text-sm">
                <div class="flex"><dt class="w-28 text-slate-500">Nama</dt><dd class="font-medium">{{ $snap['name'] ?? $payslip->employee?->name }}</dd></div>
                <div class="flex"><dt class="w-28 text-slate-500">No. Induk</dt><dd>{{ $snap['employee_no'] ?? '—' }}</dd></div>
                <div class="flex"><dt class="w-28 text-slate-500">Divisi</dt><dd>{{ $snap['division'] ?? '—' }}</dd></div>
                <div class="flex"><dt class="w-28 text-slate-500">Lembur</dt><dd>{{ $durasi($payslip->overtime_minutes) }}</dd></div>
            </dl>

            <div class="py-4">
                @include('slip._rincian', ['items' => $bonus, 'subtotal' => false])
            </div>

            <div class="mt-2 flex items-center justify-between rounded-lg bg-emerald-700 px-4 py-3 text-white">
                <span class="font-medium">Total Bonus</span>
                <span class="text-xl font-semibold tabular-nums">Rp {{ $rp($payslip->total_bonus) }}</span>
            </div>

            <p class="mt-4 text-xs text-slate-400">
                Bonus ini di luar gaji: take home pay di slip gaji tidak memuat angka ini.
            </p>
        </div>
    @endif
</div>
@endsection
