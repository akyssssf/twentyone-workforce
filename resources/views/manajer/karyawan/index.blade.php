@extends('layouts.app')
@section('title', 'Karyawan')

@section('content')
<h1 class="mb-6 text-2xl font-semibold tracking-tight">Karyawan</h1>

<div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-2 font-medium">No. Induk</th>
                    <th class="px-4 py-2 font-medium">Nama</th>
                    <th class="px-4 py-2 font-medium">Divisi</th>
                    <th class="px-4 py-2 font-medium">PIN aktif</th>
                    <th class="px-4 py-2 font-medium">Shift preferensi</th>
                    <th class="px-4 py-2 font-medium">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($employees as $e)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-2 font-mono text-xs">{{ $e->employee_no }}</td>
                        <td class="px-4 py-2 font-medium">{{ $e->name }}</td>
                        <td class="px-4 py-2">
                            @foreach ($e->divisions as $d)
                                <span class="mr-1 inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs">
                                    <span class="h-2 w-2 rounded-full" style="background: {{ $d->color }}"></span>
                                    {{ $d->name }}{{ $d->pivot->is_primary ? '' : '*' }}
                                </span>
                            @endforeach
                        </td>
                        <td class="px-4 py-2 font-mono text-xs">{{ $e->devices->firstWhere('valid_to', null)?->pin ?? '—' }}</td>
                        <td class="px-4 py-2 text-slate-500">{{ $e->defaultShift?->name ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <x-status-badge :warna="$e->employment_status === 'active' ? 'emerald' : 'slate'"
                                            :label="ucfirst($e->employment_status)" />
                        </td>
                        <td class="px-4 py-2 text-right">
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
