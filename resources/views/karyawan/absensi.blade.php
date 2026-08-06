@extends('layouts.app')
@section('title', 'Absensi Saya')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">Absensi Saya</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $bulan->translatedFormat('F Y') }}</p>
    </div>
    <form method="GET" class="flex items-end gap-2">
        <input type="month" name="bulan" value="{{ $bulan->format('Y-m') }}"
               class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
        <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Lihat</button>
    </form>
</div>

<div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
    <x-kartu label="Hadir" :nilai="$ringkasan['hadir']" warna="text-emerald-600" />
    <x-kartu label="Terlambat" :nilai="$ringkasan['telat']" warna="text-amber-600" />
    <x-kartu label="Pulang Cepat" :nilai="$ringkasan['pulang_cepat']" warna="text-orange-600" />
    <x-kartu label="Alpha" :nilai="$ringkasan['alpha']" warna="text-red-600" />
    <x-kartu label="Total telat" :nilai="$ringkasan['total_telat_menit'].' m'" />
    <x-kartu label="Lembur" :nilai="round($ringkasan['lembur_menit']/60, 1).' j'" warna="text-indigo-600" />
</div>

<div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-2 font-medium">Tanggal</th>
                    <th class="px-4 py-2 font-medium">Shift</th>
                    <th class="px-4 py-2 font-medium">Jadwal</th>
                    <th class="px-4 py-2 font-medium">Masuk</th>
                    <th class="px-4 py-2 font-medium">Pulang</th>
                    <th class="px-4 py-2 font-medium">Telat</th>
                    <th class="px-4 py-2 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($attendances as $a)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-2">{{ $a->work_date->translatedFormat('D, d M') }}</td>
                        <td class="px-4 py-2 text-slate-500">{{ $a->shift?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-slate-500">{{ $a->scheduled_in?->format('H:i') ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $a->check_in_at?->format('H:i') ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $a->check_out_at?->format('H:i') ?? '—' }}</td>
                        <td class="px-4 py-2 {{ $a->late_minutes > 0 ? 'text-amber-700' : 'text-slate-400' }}">
                            {{ $a->late_minutes > 0 ? $a->late_minutes.' m' : '—' }}
                        </td>
                        <td class="px-4 py-2">
                            <x-status-badge :warna="$a->status->color()" :label="$a->status->label()" />
                            @if ($a->has_adjustment)<span class="ml-1 text-xs text-slate-400">dikoreksi</span>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-slate-400">Belum ada data.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<p class="mt-4 text-sm text-slate-500">
    Ada yang salah? <a href="{{ route('karyawan.pengajuan.create', 'correction') }}" class="font-medium text-slate-700 underline">Ajukan koreksi absensi</a>.
</p>
@endsection
