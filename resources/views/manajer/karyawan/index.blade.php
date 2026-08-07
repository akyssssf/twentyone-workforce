@extends('layouts.app')
@section('title', 'Karyawan')
@section('lebar', 'max-w-6xl')

@section('content')

<div class="mb-6">
    <h1 class="text-xl font-semibold tracking-tight sm:text-2xl">Karyawan</h1>
    <p class="mt-1 text-sm text-slate-500">{{ $employees->count() }} orang cocok dengan filter ini.</p>
</div>

{{-- Penyaring --}}
<form method="GET" class="mb-6 kartu p-4">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div class="lg:col-span-2">
            <label for="cari" class="label">Cari nama / PIN</label>
            <input id="cari" type="text" name="cari" value="{{ $filter['cari'] }}" placeholder="ketik nama atau PIN..."
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
        </div>
        <div>
            <label for="divisi" class="label">Divisi</label>
            <select id="divisi" name="divisi"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
                <option value="">Semua</option>
                @foreach ($divisions as $d)
                    <option value="{{ $d->id }}" @selected((string) $filter['divisi'] === (string) $d->id)>{{ $d->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="shift" class="label">Shift</label>
            <select id="shift" name="shift"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
                <option value="">Semua</option>
                @foreach ($shifts as $s)
                    <option value="{{ $s->id }}" @selected((string) $filter['shift'] === (string) $s->id)>{{ $s->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="status" class="label">Status</label>
            <select id="status" name="status"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
                <option value="">Semua</option>
                <option value="aktif" @selected($filter['status'] === 'aktif')>Aktif</option>
                <option value="nonaktif" @selected($filter['status'] === 'nonaktif')>Nonaktif</option>
                <option value="tidak_diabsen" @selected($filter['status'] === 'tidak_diabsen')>Tidak diabsen</option>
                <option value="tanpa_wa" @selected($filter['status'] === 'tanpa_wa')>Tanpa nomor WA</option>
            </select>
        </div>
        <div class="flex items-end gap-2 lg:col-span-5">
            <button type="submit" class="btn-utama">Saring</button>
            <a href="{{ route('manajer.karyawan.index') }}"
               class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                Reset
            </a>
        </div>
    </div>
</form>

<div class="overflow-hidden kartu">
    @if ($employees->isEmpty())
        <x-kosong pesan="Tidak ada karyawan yang cocok dengan filter ini." />
    @else
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
                            @if (blank($e->phone))
                                <span class="ml-1" title="Kode lembur & pemberitahuan tidak akan sampai">
                                    <x-status-badge warna="amber" label="Tanpa WA" />
                                </span>
                            @endif
                        </td>
                        <td class="text-right">
                            <a href="{{ route('manajer.karyawan.show', $e) }}" class="font-medium text-slate-700 hover:underline">Detail</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
<p class="mt-3 text-xs text-slate-500">* divisi sekunder — bisa membantu di divisi itu saat kekurangan orang.</p>
@endsection
