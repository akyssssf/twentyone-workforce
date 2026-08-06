@extends('layouts.app')
@section('title', $employee->name)

@section('content')
<div class="mx-auto max-w-3xl">
    <a href="{{ route('manajer.karyawan.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Semua karyawan</a>
    <h1 class="mb-6 mt-2 text-2xl font-semibold tracking-tight">{{ $employee->name }}</h1>

    <div class="mb-6 rounded-xl border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-5 py-3"><h2 class="font-semibold">Data</h2></div>
        <dl class="divide-y divide-slate-100 text-sm">
            <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">No. Induk</dt><dd>{{ $employee->employee_no }}</dd></div>
            <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">Bergabung</dt><dd>{{ $employee->joined_at?->translatedFormat('d M Y') ?? '—' }}</dd></div>
            <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">Shift preferensi</dt><dd>{{ $employee->defaultShift?->name ?? '—' }}</dd></div>
            <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">Gaji pokok</dt>
                <dd>Rp {{ number_format($employee->baseSalaryOn(now()), 0, ',', '.') }}</dd></div>
        </dl>
    </div>

    <div class="mb-6 rounded-xl border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-5 py-3">
            <h2 class="font-semibold">Pemetaan PIN Mesin</h2>
            <p class="text-xs text-slate-500">Berperiode — PIN yang berpindah orang tidak menarik riwayat absensi lama ikut pindah.</p>
        </div>
        <table class="min-w-full text-sm">
            <tbody class="divide-y divide-slate-100">
                @foreach ($employee->devices as $d)
                    <tr>
                        <td class="px-5 py-2 font-mono">{{ $d->pin }}</td>
                        <td class="px-5 py-2 text-slate-500">{{ $d->cloud_id }}</td>
                        <td class="px-5 py-2 text-slate-500">
                            {{ $d->valid_from->translatedFormat('d M Y') }} –
                            {{ $d->valid_to?->translatedFormat('d M Y') ?? 'sekarang' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-5 py-3"><h2 class="font-semibold">Divisi &amp; Kompetensi</h2></div>
        <form method="POST" action="{{ route('manajer.karyawan.divisi', $employee) }}" class="space-y-3 px-5 py-4">
            @csrf
            @php $dimiliki = $employee->divisions->pluck('id')->all(); $utama = $employee->primaryDivision()?->id; @endphp
            @foreach ($divisions as $d)
                <label class="flex items-center gap-3 text-sm">
                    <input type="checkbox" name="divisions[]" value="{{ $d->id }}" @checked(in_array($d->id, $dimiliki)) class="rounded border-slate-300">
                    <span class="h-3 w-3 rounded-full" style="background: {{ $d->color }}"></span>
                    <span class="flex-1">{{ $d->name }}</span>
                    <label class="flex items-center gap-1.5 text-xs text-slate-500">
                        <input type="radio" name="primary" value="{{ $d->id }}" @checked($utama === $d->id) class="border-slate-300">
                        utama
                    </label>
                </label>
            @endforeach
            <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Simpan</button>
        </form>
    </div>
</div>
@endsection
