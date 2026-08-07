@extends('layouts.app')
@section('title', 'Pengajuan Saya')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <h1 class="text-xl font-semibold tracking-tight sm:text-2xl">Pengajuan Saya</h1>
    <div class="flex flex-wrap gap-2">
        @foreach (['leave' => 'Cuti / Izin', 'swap' => 'Tukar Shift', 'correction' => 'Koreksi'] as $type => $label)
            <a href="{{ route('karyawan.pengajuan.create', $type) }}"
               class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium hover:bg-slate-50">+ {{ $label }}</a>
        @endforeach
    </div>
</div>

@if ($menungguJawaban->isNotEmpty())
    <div class="pemberitahuan mb-5 border-amber-200 bg-amber-50">
        <p class="mb-2 text-sm font-semibold text-amber-900">Menunggu jawaban Anda</p>
        @foreach ($menungguJawaban as $p)
            <div class="flex items-center justify-between text-sm text-amber-900">
                <span>{{ $p->employee?->name }} — tukar shift {{ $p->swap?->requesterAssignment?->work_date?->translatedFormat('d M') }}</span>
                <a href="{{ route('karyawan.pengajuan.show', $p) }}" class="font-medium underline">Jawab</a>
            </div>
        @endforeach
    </div>
@endif

{{-- Sama seperti Absensi Saya: di ponsel jadi kartu, tabel penuh dari
     layar sedang ke atas. --}}
<div class="space-y-2 sm:hidden">
    @forelse ($requests as $r)
        <a href="{{ route('karyawan.pengajuan.show', $r) }}" class="kartu block p-3">
            <div class="flex items-center justify-between gap-2">
                <span class="font-medium">{{ $r->type->shortLabel() }}</span>
                <x-status-badge :warna="$r->status->color()" :label="$r->status->label()" />
            </div>
            <div class="mt-1 flex items-center justify-between gap-2 text-xs text-slate-500">
                <span class="font-mono">{{ $r->code }}</span>
                <span>{{ $r->submitted_at?->translatedFormat('d M Y') }}</span>
            </div>
        </a>
    @empty
        <x-kosong pesan="Belum ada pengajuan." />
    @endforelse
</div>

<div class="hidden overflow-hidden kartu sm:block">
    <div class="tabel-bungkus">
    <table class="tabel">
        <thead>
            <tr>
                <th >Kode</th>
                <th >Jenis</th>
                <th >Diajukan</th>
                <th >Status</th>
                <th ></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($requests as $r)
                <tr >
                    <td class="font-mono text-xs">{{ $r->code }}</td>
                    <td >{{ $r->type->shortLabel() }}</td>
                    <td class="text-slate-500">{{ $r->submitted_at?->translatedFormat('d M Y') }}</td>
                    <td ><x-status-badge :warna="$r->status->color()" :label="$r->status->label()" /></td>
                    <td class="text-right">
                        <a href="{{ route('karyawan.pengajuan.show', $r) }}" class="font-medium text-slate-700 hover:underline">Lihat</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">Belum ada pengajuan.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>

<div class="mt-4">{{ $requests->links() }}</div>
@endsection
