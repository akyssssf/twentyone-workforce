@extends('layouts.app')
@section('title', 'Pengajuan')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-xl font-semibold tracking-tight sm:text-2xl">Pengajuan</h1>
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

<div class="overflow-hidden kartu">
    <table class="tabel">
        <thead>
            <tr>
                <th >Kode</th>
                <th >Karyawan</th>
                <th >Jenis</th>
                <th >Rincian</th>
                <th>Pengganti</th>
                <th>Status</th>
                <th ></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($requests as $r)
                <tr >
                    <td class="font-mono text-xs">{{ $r->code }}</td>
                    <td >
                        <div class="flex items-center gap-2.5">
                            <x-avatar :employee="$r->employee" ukuran="sm" />
                            <span class="font-medium">{{ $r->employee?->name }}</span>
                        </div>
                    </td>
                    <td >{{ $r->type->shortLabel() }}</td>
                    <td class="text-slate-500">
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
                    <td>
                        @if ($r->substitute)
                            <div class="flex items-center gap-1.5">
                                <x-avatar :employee="$r->substitute" ukuran="xs" />
                                <span class="truncate">{{ $r->substitute->name }}</span>
                                @if ($r->substitute_accepted_at)
                                    <span class="text-emerald-600" title="Bersedia">&check;</span>
                                @elseif ($r->substitute_rejected_at)
                                    <span class="text-red-600" title="Tidak bisa">&times;</span>
                                @endif
                            </div>
                        @else
                            <span class="text-slate-300">—</span>
                        @endif
                    </td>
                    <td><x-status-badge :warna="$r->status->color()" :label="$r->status->label()" /></td>
                    <td class="text-right">
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
