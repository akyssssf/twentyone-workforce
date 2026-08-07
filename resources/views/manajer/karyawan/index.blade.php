@extends('layouts.app')
@section('title', 'Karyawan')
@section('lebar', 'max-w-6xl')

@section('content')

<div class="overflow-hidden kartu">
    <div class="tabel-bungkus">
        <table class="tabel">
            <thead>
                <tr>
                    <th >Foto</th>
                    <th >No. Induk</th>
                    <th >Nama</th>
                    <th >Divisi</th>
                    <th >PIN aktif</th>
                    <th >Shift preferensi</th>
                    <th >Status</th>
                    <th ></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($employees as $e)
                    <tr >
                        <td >
                            @if ($photo = $e->avatarUrl())
                                <a href="{{ $photo }}" target="_blank" rel="noopener noreferrer">
                                    <img src="{{ $photo }}" alt="{{ $e->name }}" class="h-10 w-10 rounded-full object-cover ring-2 ring-slate-200 transition hover:scale-105">
                                </a>
                            @else
                                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-200 font-semibold text-slate-600 text-xs">
                                    {{ strtoupper(substr($e->name, 0, 2)) }}
                                </div>
                            @endif
                        </td>
                        <td class="font-mono text-xs">{{ $e->employee_no }}</td>
                        <td >{{ $e->name }}</td>
                        <td >
                            @foreach ($e->divisions as $d)
                                <span class="mr-1 inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs">
                                    <span class="h-2 w-2 rounded-full" style="background: {{ $d->color }}"></span>
                                    {{ $d->name }}{{ $d->pivot->is_primary ? '' : '*' }}
                                </span>
                            @endforeach
                        </td>
                        <td class="font-mono text-xs">{{ $e->devices->firstWhere('valid_to', null)?->pin ?? '—' }}</td>
                        <td class="text-slate-500">{{ $e->defaultShift?->name ?? '—' }}</td>
                        <td >
                            <x-status-badge :warna="$e->employment_status === 'active' ? 'emerald' : 'slate'"
                                            :label="ucfirst($e->employment_status)" />
                            @unless ($e->tracks_attendance)
                                <span class="ml-1"><x-status-badge warna="indigo" label="Tidak diabsen" /></span>
                            @endunless
                        </td>
                        <td class="text-right">
                            <a href="{{ route('manajer.karyawan.show', $e) }}" class="font-medium text-slate-700 hover:underline">Detail</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
<p class="mt-3 text-xs text-slate-500">* divisi sekunder — bisa membantu di divisi itu saat kekurangan orang.</p>
@endsection
