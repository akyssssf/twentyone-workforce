@extends('layouts.app')
@section('title', 'Roster ' . $roster->label())
@section('lebar', 'max-w-none')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-xl font-semibold tracking-tight sm:text-2xl">Roster {{ $roster->label() }}</h1>
        <p class="mt-1 flex items-center gap-2 text-sm text-slate-500">
            <x-status-badge :warna="$roster->status->value === 'published' ? 'emerald' : 'amber'" :label="$roster->status->label()" />
            {{ $employees->count() }} karyawan &middot; {{ count($dates) }} hari
        </p>
    </div>

    <div class="flex flex-wrap gap-2">
        <form method="POST" action="{{ route('manajer.roster.generate', $roster) }}">
            @csrf
            <input type="hidden" name="overwrite" value="1">
            <button class="btn-netral">
                Isi otomatis
            </button>
        </form>

        @if ($roster->status->isEditable())
            <form method="POST" action="{{ route('manajer.roster.publish', $roster) }}">
                @csrf
                <button class="btn-utama">
                    Terbitkan
                </button>
            </form>
        @endif
    </div>
</div>

{{-- Hasil validasi. Error memblokir penerbitan, warning tidak — karena dengan
     18 orang, roster yang memenuhi semua kebutuhan minimum sekaligus libur
     mingguan memang mustahil. --}}
@if ($issues['errors']->isNotEmpty())
    <div class="pemberitahuan mb-4 border-red-200 bg-red-50 text-red-800">
        <p class="mb-1 font-semibold">{{ $issues['errors']->count() }} masalah memblokir penerbitan:</p>
        <ul class="list-inside list-disc space-y-0.5">
            @foreach ($issues['errors']->take(10) as $e)<li>{{ $e['message'] }}</li>@endforeach
        </ul>
    </div>
@endif

@if ($issues['warnings']->isNotEmpty())
    <details class="pemberitahuan mb-5 border-amber-200 bg-amber-50 text-amber-900">
        <summary class="cursor-pointer font-semibold">
            {{ $issues['warnings']->count() }} peringatan — roster tetap bisa diterbitkan
        </summary>
        <ul class="mt-2 max-h-64 list-inside list-disc space-y-0.5 overflow-y-auto">
            @foreach ($issues['warnings'] as $w)<li>{{ $w['message'] }}</li>@endforeach
        </ul>
    </details>
@endif

<div class="overflow-x-auto kartu">
    <table class="min-w-full text-xs">
        <thead class="bg-slate-50">
            <tr>
                <th class="sticky left-0 z-10 bg-slate-50 px-3 py-2 text-left font-medium text-slate-500">Karyawan</th>
                @foreach ($dates as $d)
                    <th @class([
                        'px-1 py-2 text-center font-medium',
                        'text-red-500' => $d->isSunday(),
                        'text-slate-500' => ! $d->isSunday(),
                    ])>
                        <div>{{ $d->day }}</div>
                        <div class="text-[10px] font-normal">{{ $d->translatedFormat('D') }}</div>
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($employees as $employee)
                <tr class="hover:bg-slate-50/50">
                    <td class="sticky left-0 z-10 bg-white px-3 py-1.5 whitespace-nowrap">
                        <div class="flex items-center gap-2">
                            <x-avatar :employee="$employee" ukuran="xs" />
                            <div>
                                <div class="font-medium">{{ $employee->name }}</div>
                                <div class="text-[10px] text-slate-400">{{ $employee->primaryDivision()?->name }}</div>
                            </div>
                        </div>
                    </td>

                    @foreach ($dates as $d)
                        @php
                            $key = $employee->id . '|' . $d->toDateString();
                            $rows = $assignments->get($key, collect());
                            $working = $rows->filter(fn ($a) => $a->status->isWorking());
                        @endphp
                        <td class="px-0.5 py-1 text-center align-top">
                            @if ($working->isNotEmpty())
                                @foreach ($working as $a)
                                    <div class="mx-auto mb-0.5 w-full rounded px-1 py-0.5 text-[10px] font-medium text-white"
                                         style="background: {{ $a->shift?->color ?? '#475569' }}"
                                         title="{{ $a->shift?->name }} — {{ $a->division?->name }}">
                                        {{ strtoupper(substr($a->shift?->code ?? '?', 0, 1)) }}
                                    </div>
                                @endforeach
                            @elseif ($rows->isNotEmpty())
                                @php $first = $rows->first(); @endphp
                                <div class="rounded bg-slate-100 px-1 py-0.5 text-[10px] text-slate-500"
                                     title="{{ $first->status->label() }}">
                                    {{ $first->status->value === 'leave' ? 'C' : '·' }}
                                </div>
                            @else
                                <div class="text-[10px] text-slate-300">–</div>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="mt-4 flex flex-wrap items-center gap-4 text-xs text-slate-500">
    @foreach ($shifts as $s)
        <span class="flex items-center gap-1.5">
            <span class="h-3 w-3 rounded" style="background: {{ $s->color ?? '#475569' }}"></span>
            {{ strtoupper(substr($s->code, 0, 1)) }} = {{ $s->name }}
        </span>
    @endforeach
    <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded bg-slate-100"></span> · = libur, C = cuti</span>
</div>

{{-- Ubah satu sel --}}
<div class="mt-6 kartu p-4">
    <h2 class="mb-3 font-semibold">Ubah Jadwal</h2>
    <form method="POST" action="{{ route('manajer.roster.assign', $roster) }}" class="flex flex-wrap items-end gap-3">
        @csrf
        <div>
            <label class="label">Karyawan</label>
            <select name="employee_id" required class="kolom mt-1">
                @foreach ($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="label">Tanggal</label>
            <input type="date" name="work_date" required
                   min="{{ $roster->startDate()->toDateString() }}" max="{{ $roster->endDate()->toDateString() }}"
                   class="kolom mt-1">
        </div>
        <div>
            <label class="label">Shift</label>
            <select name="shift_id" class="kolom mt-1">
                <option value="">Libur</option>
                @foreach ($shifts as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="label">Bertugas sebagai</label>
            <select name="division_id" class="kolom mt-1">
                <option value="">Divisi utama</option>
                @foreach ($divisions as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
            </select>
        </div>
        <button class="btn-utama">Simpan</button>
    </form>
</div>
@endsection
