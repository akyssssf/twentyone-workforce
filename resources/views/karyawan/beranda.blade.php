@extends('layouts.app')
@section('title', 'Beranda')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-semibold tracking-tight">Halo, {{ $employee->name }}</h1>
    <p class="mt-1 text-sm text-slate-500">
        {{ $employee->primaryDivision()?->name }} &middot; {{ now()->translatedFormat('l, d F Y') }}
    </p>
</div>

@if ($menungguJawaban->isNotEmpty())
    <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
        <p class="mb-2 text-sm font-semibold text-amber-900">Ada yang menunggu jawaban Anda</p>
        @foreach ($menungguJawaban as $p)
            <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-amber-900">
                <span>
                    {{ $p->employee?->name }} ingin menukar shift
                    {{ $p->swap?->requesterAssignment?->work_date?->translatedFormat('d M') }}
                    ({{ $p->swap?->requesterAssignment?->shift?->name }})
                </span>
                <a href="{{ route('karyawan.pengajuan.show', $p) }}" class="font-medium underline">Jawab</a>
            </div>
        @endforeach
    </div>
@endif

<div class="grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-6">
        {{-- Hari ini --}}
        <div class="rounded-xl border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-4 py-3"><h2 class="font-semibold">Hari Ini</h2></div>
            <div class="px-4 py-4">
                @forelse ($jadwalHariIni as $j)
                    <div class="mb-3 flex flex-wrap items-center gap-3 last:mb-0">
                        @if ($j->shift)
                            <span class="rounded-md px-2.5 py-1 text-sm font-medium text-white" style="background: {{ $j->shift->color ?? '#475569' }}">
                                {{ $j->shift->name }}
                            </span>
                            <span class="text-sm text-slate-600">
                                {{ substr($j->shift->start_time, 0, 5) }} – {{ substr($j->shift->end_time, 0, 5) }}
                            </span>
                            <span class="text-sm text-slate-400">sebagai {{ $j->division?->name }}</span>
                        @else
                            <x-status-badge warna="slate" :label="$j->status->label()" />
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-slate-400">Tidak ada jadwal hari ini.</p>
                @endforelse

                @foreach ($absensiHariIni as $a)
                    <div class="mt-3 flex flex-wrap gap-4 rounded-lg bg-slate-50 px-3 py-2 text-sm">
                        <span>Masuk <strong>{{ $a->check_in_at?->format('H:i') ?? '—' }}</strong></span>
                        <span>Pulang <strong>{{ $a->check_out_at?->format('H:i') ?? '—' }}</strong></span>
                        @if ($a->late_minutes > 0)
                            <span class="text-amber-700">Telat {{ $a->late_minutes }} menit</span>
                        @endif
                        @if ($a->overtime_minutes > 0)
                            <span class="text-indigo-700">Lembur {{ round($a->overtime_minutes / 60, 1) }} jam</span>
                        @endif
                        <x-status-badge :warna="$a->status->color()" :label="$a->status->label()" />
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Jadwal mendatang --}}
        <div class="rounded-xl border border-slate-200 bg-white">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <h2 class="font-semibold">Jadwal Berikutnya</h2>
                <a href="{{ route('karyawan.jadwal') }}" class="text-sm text-slate-500 hover:underline">Lihat sebulan</a>
            </div>
            <div class="divide-y divide-slate-100">
                @forelse ($jadwalMendatang as $j)
                    <div class="flex items-center justify-between px-4 py-2.5 text-sm">
                        <span>{{ $j->work_date->translatedFormat('l, d M') }}</span>
                        @if ($j->shift)
                            <span class="rounded px-2 py-0.5 text-xs font-medium text-white" style="background: {{ $j->shift->color ?? '#475569' }}">
                                {{ $j->shift->name }}
                            </span>
                        @else
                            <span class="text-xs text-slate-400">{{ $j->status->label() }}</span>
                        @endif
                    </div>
                @empty
                    <p class="px-4 py-6 text-sm text-slate-400">Roster bulan ini belum terbit.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="space-y-6">
        {{-- Aksi cepat --}}
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <h2 class="mb-3 font-semibold">Ajukan</h2>
            <div class="grid grid-cols-2 gap-2">
                @foreach ([
                    'leave' => 'Cuti / Izin',
                    'overtime' => 'Lembur',
                    'swap' => 'Tukar Shift',
                    'correction' => 'Koreksi Absensi',
                ] as $type => $label)
                    <a href="{{ route('karyawan.pengajuan.create', $type) }}"
                       class="rounded-lg border border-slate-300 px-3 py-2 text-center text-sm font-medium hover:bg-slate-50">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Saldo cuti --}}
        <div class="rounded-xl border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-4 py-3"><h2 class="font-semibold">Saldo Cuti</h2></div>
            <div class="divide-y divide-slate-100">
                @forelse ($saldoCuti as $saldo)
                    <div class="flex items-center justify-between px-4 py-2.5 text-sm">
                        <span>{{ $saldo->leaveType?->name }}</span>
                        <span class="font-semibold">{{ rtrim(rtrim(number_format($saldo->remaining(), 1), '0'), '.,') }} hari</span>
                    </div>
                @empty
                    <p class="px-4 py-5 text-sm text-slate-400">Belum ada saldo tercatat.</p>
                @endforelse
            </div>
        </div>

        {{-- Pengajuan berjalan --}}
        <div class="rounded-xl border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-4 py-3"><h2 class="font-semibold">Pengajuan Berjalan</h2></div>
            <div class="divide-y divide-slate-100">
                @forelse ($pengajuanTerbuka as $p)
                    <a href="{{ route('karyawan.pengajuan.show', $p) }}" class="flex items-center justify-between px-4 py-2.5 text-sm hover:bg-slate-50">
                        <span>{{ $p->type->shortLabel() }}</span>
                        <x-status-badge :warna="$p->status->color()" :label="$p->status->label()" />
                    </a>
                @empty
                    <p class="px-4 py-5 text-sm text-slate-400">Tidak ada.</p>
                @endforelse
            </div>
        </div>

        @if ($slipTerbaru)
            <a href="{{ route('karyawan.slip.show', $slipTerbaru) }}" class="block rounded-xl border border-slate-200 bg-white p-4 hover:bg-slate-50">
                <div class="text-xs text-slate-500">Slip gaji terbaru</div>
                <div class="mt-1 text-lg font-semibold">Rp {{ number_format($slipTerbaru->take_home_pay, 0, ',', '.') }}</div>
                <div class="text-xs text-slate-400">{{ $slipTerbaru->run->period->label() }}</div>
            </a>
        @endif
    </div>
</div>
@endsection
