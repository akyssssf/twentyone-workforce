@extends('layouts.app')
@section('title', 'Payroll')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">Payroll</h1>
        <p class="mt-1 text-sm text-slate-500">
            Periode berjalan dari tanggal 21 bulan sebelumnya sampai tanggal 20, dibayar tanggal 21.
        </p>
    </div>

    <form method="POST" action="{{ route('manajer.payroll.store') }}" class="flex items-end gap-2">
        @csrf
        <div>
            <label class="block text-xs font-medium text-slate-500">Bulan pembayaran</label>
            <input type="month" name="bulan" id="bulan" value="{{ $bulanIni->format('Y-m') }}"
                   class="mt-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                   onchange="document.getElementById('year').value=this.value.split('-')[0];document.getElementById('month').value=this.value.split('-')[1];">
        </div>
        <input type="hidden" name="year" id="year" value="{{ $bulanIni->year }}">
        <input type="hidden" name="month" id="month" value="{{ $bulanIni->month }}">
        <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Buat periode</button>
    </form>
</div>

<div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-2 font-medium">Kode</th>
                <th class="px-4 py-2 font-medium">Periode kerja</th>
                <th class="px-4 py-2 font-medium">Dibayar</th>
                <th class="px-4 py-2 font-medium">Status</th>
                <th class="px-4 py-2 font-medium">Versi</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($periods as $p)
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-2 font-mono text-xs">{{ $p->code }}</td>
                    <td class="px-4 py-2">{{ $p->label() }}</td>
                    <td class="px-4 py-2 text-slate-500">{{ $p->pay_date->translatedFormat('d M Y') }}</td>
                    <td class="px-4 py-2"><x-status-badge :warna="$p->status->color()" :label="$p->status->label()" /></td>
                    <td class="px-4 py-2 text-slate-500">{{ $p->runs->max('version') ?? '—' }}</td>
                    <td class="px-4 py-2 text-right">
                        <a href="{{ route('manajer.payroll.show', $p) }}" class="font-medium text-slate-700 hover:underline">Buka</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">Belum ada periode payroll.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
