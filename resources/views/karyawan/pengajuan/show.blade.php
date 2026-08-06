@extends('layouts.app')
@section('title', 'Pengajuan ' . $pengajuan->code)

@section('content')
<div class="mx-auto max-w-2xl">
    <a href="{{ route('karyawan.pengajuan.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Kembali</a>

    <div class="mt-3 rounded-xl border border-slate-200 bg-white">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div>
                <h1 class="text-lg font-semibold">{{ $pengajuan->type->label() }}</h1>
                <p class="text-sm text-slate-500">
                    {{ $pengajuan->code }}
                    @unless ($milikSaya) &middot; diajukan {{ $pengajuan->employee?->name }} @endunless
                </p>
            </div>
            <x-status-badge :warna="$pengajuan->status->color()" :label="$pengajuan->status->label()" />
        </div>

        @php $d = $pengajuan->detail(); @endphp
        <dl class="divide-y divide-slate-100 text-sm">
            @switch($pengajuan->type->value)
                @case('leave')
                    <div class="flex px-5 py-3"><dt class="w-36 text-slate-500">Jenis</dt><dd>{{ $d?->leaveType?->name }}</dd></div>
                    <div class="flex px-5 py-3"><dt class="w-36 text-slate-500">Tanggal</dt><dd>{{ $d?->start_date?->translatedFormat('d M Y') }} – {{ $d?->end_date?->translatedFormat('d M Y') }} ({{ $d?->total_days }} hari)</dd></div>
                    @break
                @case('overtime')
                    <div class="flex px-5 py-3"><dt class="w-36 text-slate-500">Tanggal</dt><dd>{{ $d?->work_date?->translatedFormat('d M Y') }}</dd></div>
                    <div class="flex px-5 py-3"><dt class="w-36 text-slate-500">Jam</dt><dd>{{ substr($d?->planned_start ?? '', 0, 5) }} – {{ substr($d?->planned_end ?? '', 0, 5) }}</dd></div>
                    @break
                @case('swap')
                    <div class="flex px-5 py-3"><dt class="w-36 text-slate-500">Jadwal</dt><dd>{{ $d?->requesterAssignment?->work_date?->translatedFormat('d M Y') }} — {{ $d?->requesterAssignment?->shift?->name }}</dd></div>
                    <div class="flex px-5 py-3"><dt class="w-36 text-slate-500">Rekan</dt><dd>{{ $d?->partner?->name }}</dd></div>
                    @break
                @case('correction')
                    <div class="flex px-5 py-3"><dt class="w-36 text-slate-500">Tanggal</dt><dd>{{ $d?->work_date?->translatedFormat('d M Y') }}</dd></div>
                    <div class="flex px-5 py-3"><dt class="w-36 text-slate-500">Kasus</dt><dd>{{ str_replace('_', ' ', $d?->correction_type ?? '') }}</dd></div>
                    @break
            @endswitch
            <div class="flex px-5 py-3"><dt class="w-36 shrink-0 text-slate-500">Alasan</dt><dd>{{ $d?->reason }}</dd></div>

            @if ($pengajuan->decided_at)
                <div class="flex px-5 py-3"><dt class="w-36 text-slate-500">Keputusan</dt>
                    <dd>{{ $pengajuan->decided_at->translatedFormat('d M Y H:i') }}
                        @if ($pengajuan->decision_note)<div class="text-slate-500">{{ $pengajuan->decision_note }}</div>@endif
                    </dd></div>
            @endif
        </dl>

        @if ($sayaRekan && $pengajuan->status->value === 'pending_peer')
            <div class="flex flex-wrap gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4">
                <form method="POST" action="{{ route('karyawan.pengajuan.respond', $pengajuan) }}">
                    @csrf
                    <input type="hidden" name="accepted" value="1">
                    <button class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">Saya bersedia</button>
                </form>
                <form method="POST" action="{{ route('karyawan.pengajuan.respond', $pengajuan) }}">
                    @csrf
                    <input type="hidden" name="accepted" value="0">
                    <button class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium hover:bg-slate-50">Tidak bisa</button>
                </form>
            </div>
        @endif

        @if ($milikSaya && $pengajuan->status->isOpen())
            <div class="border-t border-slate-200 bg-slate-50 px-5 py-4">
                <form method="POST" action="{{ route('karyawan.pengajuan.cancel', $pengajuan) }}">
                    @csrf
                    <button class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium hover:bg-slate-50">
                        Batalkan pengajuan
                    </button>
                </form>
            </div>
        @endif
    </div>
</div>
@endsection
