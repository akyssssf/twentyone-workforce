@extends('layouts.app')
@section('title', 'Pengajuan Saya')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <h1 class="text-2xl font-semibold tracking-tight">Pengajuan Saya</h1>
    <div class="flex flex-wrap gap-2">
        @foreach (['leave' => 'Cuti / Izin', 'overtime' => 'Lembur', 'swap' => 'Tukar Shift', 'correction' => 'Koreksi'] as $type => $label)
            <a href="{{ route('karyawan.pengajuan.create', $type) }}"
               class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium hover:bg-slate-50">+ {{ $label }}</a>
        @endforeach
    </div>
</div>

@if ($menungguJawaban->isNotEmpty())
    <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
        <p class="mb-2 text-sm font-semibold text-amber-900">Menunggu jawaban Anda</p>
        @foreach ($menungguJawaban as $p)
            <div class="flex items-center justify-between text-sm text-amber-900">
                <span>{{ $p->employee?->name }} — tukar shift {{ $p->swap?->requesterAssignment?->work_date?->translatedFormat('d M') }}</span>
                <a href="{{ route('karyawan.pengajuan.show', $p) }}" class="font-medium underline">Jawab</a>
            </div>
        @endforeach
    </div>
@endif

<div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-2 font-medium">Kode</th>
                <th class="px-4 py-2 font-medium">Jenis</th>
                <th class="px-4 py-2 font-medium">Diajukan</th>
                <th class="px-4 py-2 font-medium">Status</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($requests as $r)
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-2 font-mono text-xs">{{ $r->code }}</td>
                    <td class="px-4 py-2">{{ $r->type->shortLabel() }}</td>
                    <td class="px-4 py-2 text-slate-500">{{ $r->submitted_at?->translatedFormat('d M Y') }}</td>
                    <td class="px-4 py-2"><x-status-badge :warna="$r->status->color()" :label="$r->status->label()" /></td>
                    <td class="px-4 py-2 text-right">
                        <a href="{{ route('karyawan.pengajuan.show', $r) }}" class="font-medium text-slate-700 hover:underline">Lihat</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">Belum ada pengajuan.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $requests->links() }}</div>
@endsection
