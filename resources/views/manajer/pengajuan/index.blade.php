@extends('layouts.app')
@section('title', 'Pengajuan')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">Pengajuan</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $jumlahPending }} menunggu keputusan Anda</p>
    </div>

    <div class="flex gap-1">
        @foreach (['pending' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', 'semua' => 'Semua'] as $key => $label)
            <a href="{{ route('manajer.pengajuan.index', ['status' => $key]) }}"
               @class([
                   'rounded-md px-3 py-1.5 text-sm font-medium',
                   'bg-slate-900 text-white' => $status === $key,
                   'bg-white border border-slate-300 text-slate-600 hover:bg-slate-50' => $status !== $key,
               ])>{{ $label }}</a>
        @endforeach
    </div>
</div>

<div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-2 font-medium">Kode</th>
                <th class="px-4 py-2 font-medium">Karyawan</th>
                <th class="px-4 py-2 font-medium">Jenis</th>
                <th class="px-4 py-2 font-medium">Rincian</th>
                <th class="px-4 py-2 font-medium">Status</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($requests as $r)
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-2 font-mono text-xs">{{ $r->code }}</td>
                    <td class="px-4 py-2 font-medium">{{ $r->employee?->name }}</td>
                    <td class="px-4 py-2">{{ $r->type->shortLabel() }}</td>
                    <td class="px-4 py-2 text-slate-500">
                        @switch($r->type->value)
                            @case('leave')
                                {{ $r->leave?->leaveType?->name }},
                                {{ $r->leave?->start_date?->translatedFormat('d M') }}–{{ $r->leave?->end_date?->translatedFormat('d M') }}
                                ({{ $r->leave?->total_days }} hari)
                                @break
                            @case('overtime')
                                {{ $r->overtime?->work_date?->translatedFormat('d M') }},
                                {{ substr($r->overtime?->planned_start ?? '', 0, 5) }}–{{ substr($r->overtime?->planned_end ?? '', 0, 5) }}
                                @break
                            @case('swap')
                                dengan {{ $r->swap?->partner?->name }}
                                @break
                            @case('correction')
                                {{ $r->correction?->work_date?->translatedFormat('d M') }} —
                                {{ str_replace('_', ' ', $r->correction?->correction_type ?? '') }}
                                @break
                        @endswitch
                    </td>
                    <td class="px-4 py-2"><x-status-badge :warna="$r->status->color()" :label="$r->status->label()" /></td>
                    <td class="px-4 py-2 text-right">
                        <a href="{{ route('manajer.pengajuan.show', $r) }}" class="font-medium text-slate-700 hover:underline">Periksa</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">Tidak ada pengajuan.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $requests->links() }}</div>
@endsection
