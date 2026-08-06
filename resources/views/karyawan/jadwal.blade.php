@extends('layouts.app')
@section('title', 'Jadwal Saya')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">Jadwal Saya</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $bulan->translatedFormat('F Y') }}</p>
    </div>
    <form method="GET" class="flex items-end gap-2">
        <input type="month" name="bulan" value="{{ $bulan->format('Y-m') }}"
               class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
        <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Lihat</button>
    </form>
</div>

@if (! $roster)
    <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
        Jadwal {{ $bulan->translatedFormat('F Y') }} belum diterbitkan manager.
    </div>
@else
    <div class="grid grid-cols-7 gap-2">
        @foreach (['Sen','Sel','Rab','Kam','Jum','Sab','Min'] as $hari)
            <div class="pb-1 text-center text-xs font-medium text-slate-500">{{ $hari }}</div>
        @endforeach

        @php
            $awal = $bulan->copy()->startOfMonth();
            $offset = ($awal->dayOfWeekIso - 1);
        @endphp

        @for ($i = 0; $i < $offset; $i++)<div></div>@endfor

        @for ($d = $awal->copy(); $d->month === $bulan->month; $d->addDay())
            @php $a = $assignments->get($d->toDateString()); @endphp
            <div class="min-h-20 rounded-lg border border-slate-200 bg-white p-2">
                <div class="text-xs font-medium {{ $d->isToday() ? 'text-slate-900' : 'text-slate-400' }}">
                    {{ $d->day }}{{ $d->isToday() ? ' • hari ini' : '' }}
                </div>
                @if ($a?->shift)
                    <div class="mt-1 rounded px-1.5 py-1 text-[11px] font-medium leading-tight text-white"
                         style="background: {{ $a->shift->color ?? '#475569' }}">
                        {{ $a->shift->name }}
                        <div class="font-normal opacity-90">{{ substr($a->shift->start_time, 0, 5) }}</div>
                    </div>
                @elseif ($a)
                    <div class="mt-1 rounded bg-slate-100 px-1.5 py-1 text-[11px] text-slate-500">{{ $a->status->label() }}</div>
                @endif
            </div>
        @endfor
    </div>
@endif
@endsection
