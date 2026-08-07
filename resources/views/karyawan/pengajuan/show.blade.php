@extends('layouts.app')
@section('title', 'Pengajuan ' . $pengajuan->code)
@section('lebar', 'max-w-2xl')

@section('content')

<x-judul-halaman :judul="$pengajuan->type->label()"
                 :keterangan="$pengajuan->code . ($milikSaya ? '' : ' · diajukan ' . $pengajuan->employee?->name)"
                 :kembali="route('karyawan.pengajuan.index')" />

@php $d = $pengajuan->detail(); @endphp

<div class="kartu">
    <div class="kartu-judul">
        <div class="flex min-w-0 items-center gap-3">
            <x-avatar :employee="$pengajuan->employee" ukuran="sm" />
            <div class="min-w-0">
                <p class="truncate text-sm font-medium">{{ $pengajuan->employee?->name }}</p>
                <p class="text-xs text-slate-500">
                    {{ $pengajuan->submitted_at?->translatedFormat('d M Y H:i') }}
                </p>
            </div>
        </div>
        <x-status-badge :warna="$pengajuan->status->color()" :label="$pengajuan->status->label()" />
    </div>

    <dl class="divide-y divide-slate-50 text-sm">
        @switch($pengajuan->type->value)
            @case('leave')
                <div class="flex gap-3 px-4 py-3 sm:px-5"><dt class="w-32 shrink-0 text-slate-500">Jenis</dt><dd>{{ $d?->leaveType?->name }}</dd></div>
                <div class="flex gap-3 px-4 py-3 sm:px-5"><dt class="w-32 shrink-0 text-slate-500">Tanggal</dt>
                    <dd>{{ $d?->start_date?->translatedFormat('d M Y') }} – {{ $d?->end_date?->translatedFormat('d M Y') }}
                        <span class="text-slate-500">({{ $d?->total_days }} hari)</span></dd></div>
                @break

            @case('overtime')
                <div class="flex gap-3 px-4 py-3 sm:px-5"><dt class="w-32 shrink-0 text-slate-500">Tanggal</dt><dd>{{ $d?->work_date?->translatedFormat('d M Y') }}</dd></div>
                <div class="flex gap-3 px-4 py-3 sm:px-5"><dt class="w-32 shrink-0 text-slate-500">Jam</dt>
                    <dd>{{ substr($d?->planned_start ?? '', 0, 5) }} – {{ substr($d?->planned_end ?? '', 0, 5) }}</dd></div>
                @break

            @case('swap')
                <div class="flex gap-3 px-4 py-3 sm:px-5"><dt class="w-32 shrink-0 text-slate-500">Jadwal</dt>
                    <dd>{{ $d?->requesterAssignment?->work_date?->translatedFormat('d M Y') }} — {{ $d?->requesterAssignment?->shift?->name }}</dd></div>
                @break

            @case('correction')
                <div class="flex gap-3 px-4 py-3 sm:px-5"><dt class="w-32 shrink-0 text-slate-500">Tanggal</dt><dd>{{ $d?->work_date?->translatedFormat('d M Y') }}</dd></div>
                <div class="flex gap-3 px-4 py-3 sm:px-5"><dt class="w-32 shrink-0 text-slate-500">Kasus</dt><dd>{{ str_replace('_', ' ', $d?->correction_type ?? '') }}</dd></div>
                @break
        @endswitch

        <div class="flex gap-3 px-4 py-3 sm:px-5"><dt class="w-32 shrink-0 text-slate-500">Alasan</dt><dd>{{ $d?->reason }}</dd></div>

        {{-- Pengganti: bagian yang menentukan apakah pengajuan ini bisa maju. --}}
        <div class="flex gap-3 px-4 py-3 sm:px-5">
            <dt class="w-32 shrink-0 text-slate-500">Pengganti</dt>
            <dd class="min-w-0">
                @if ($pengajuan->substitute)
                    <div class="flex items-center gap-2">
                        <x-avatar :employee="$pengajuan->substitute" ukuran="xs" />
                        <span class="font-medium">{{ $pengajuan->substitute->name }}</span>
                    </div>
                    <div class="mt-1">
                        @if ($pengajuan->substitute_accepted_at)
                            <x-status-badge warna="emerald"
                                            :label="'Bersedia · ' . $pengajuan->substitute_accepted_at->translatedFormat('d M H:i')" />
                        @elseif ($pengajuan->substitute_rejected_at)
                            <x-status-badge warna="red" label="Tidak bisa" />
                        @else
                            <x-status-badge warna="amber" label="Menunggu jawaban" />
                        @endif
                    </div>
                    @if ($pengajuan->substitute_note)
                        <p class="mt-1 text-xs text-slate-500">"{{ $pengajuan->substitute_note }}"</p>
                    @endif
                @else
                    <span class="text-slate-400">Belum ditunjuk</span>
                @endif
            </dd>
        </div>

        @if ($pengajuan->decided_at)
            <div class="flex gap-3 px-4 py-3 sm:px-5">
                <dt class="w-32 shrink-0 text-slate-500">Keputusan</dt>
                <dd>{{ $pengajuan->decided_at->translatedFormat('d M Y H:i') }}
                    @if ($pengajuan->decision_note)
                        <div class="text-slate-500">{{ $pengajuan->decision_note }}</div>
                    @endif
                </dd>
            </div>
        @endif
    </dl>

    {{-- Kode lembur, hanya untuk pemiliknya dan hanya setelah disetujui. --}}
    @if ($milikSaya && $pengajuan->type->value === 'overtime' && $pengajuan->status->value === 'approved' && $d?->secret_code)
        <div class="border-t border-slate-100 bg-indigo-50 px-4 py-4 text-center sm:px-5">
            <p class="text-xs font-medium uppercase tracking-wide text-indigo-700">Kode lembur Anda</p>
            <p class="my-1.5 font-mono text-3xl font-bold tracking-[0.35em] text-indigo-900">{{ $d->secret_code }}</p>
            <p class="text-xs text-indigo-700">
                Aktifkan lewat beranda sebelum mulai bekerja. Kode ini hanya berlaku untuk Anda.
            </p>
        </div>
    @endif

    {{-- Jawaban pengganti --}}
    @if ($sayaRekan && $pengajuan->status->value === 'pending_peer')
        <div class="border-t border-slate-100 bg-amber-50 px-4 py-4 sm:px-5">
            <p class="mb-3 text-sm text-amber-900">
                {{ $pengajuan->employee?->name }} menunjuk Anda sebagai pengganti. Bersedia?
            </p>
            <div class="flex flex-col gap-2 sm:flex-row">
                <form method="POST" action="{{ route('karyawan.pengajuan.respond', $pengajuan) }}" class="sm:flex-1">
                    @csrf
                    <input type="hidden" name="accepted" value="1">
                    <button class="btn-setuju w-full">Saya bersedia</button>
                </form>
                <form method="POST" action="{{ route('karyawan.pengajuan.respond', $pengajuan) }}" class="sm:flex-1">
                    @csrf
                    <input type="hidden" name="accepted" value="0">
                    <button class="btn-netral w-full">Tidak bisa</button>
                </form>
            </div>
        </div>
    @endif

    @if ($milikSaya && $pengajuan->status->isOpen())
        <div class="border-t border-slate-100 px-4 py-4 sm:px-5">
            <form method="POST" action="{{ route('karyawan.pengajuan.cancel', $pengajuan) }}">
                @csrf
                <button class="btn-netral w-full sm:w-auto">Batalkan pengajuan</button>
            </form>
        </div>
    @endif
</div>

@if ($milikSaya)
    <div class="kartu mt-4 p-4 text-center">
        <p class="mb-3 text-sm text-slate-500">Mau mempercepat? Konfirmasi langsung ke admin.</p>
        <x-tombol-wa class="w-full sm:w-auto"
                     :pesan="'Halo Admin, saya ' . $pengajuan->employee?->name . ' mau konfirmasi pengajuan ' . $pengajuan->code . ' (' . $pengajuan->type->shortLabel() . ').'" />
    </div>
@endif

@endsection
