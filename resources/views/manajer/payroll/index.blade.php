@extends('layouts.app')
@section('title', 'Payroll')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-xl font-semibold tracking-tight sm:text-2xl">Payroll</h1>
        <p class="mt-1 text-sm text-slate-500">
            Periode berjalan dari tanggal 21 bulan sebelumnya sampai tanggal 20, dibayar tanggal 21.
        </p>
    </div>

    <form method="POST" action="{{ route('manajer.payroll.store') }}" class="flex items-end gap-2">
        @csrf
        <div>
            <label class="label">Bulan pembayaran</label>
            <input type="month" name="bulan" id="bulan" value="{{ $bulanIni->format('Y-m') }}"
                   class="kolom mt-1"
                   onchange="document.getElementById('year').value=this.value.split('-')[0];document.getElementById('month').value=this.value.split('-')[1];">
        </div>
        <input type="hidden" name="year" id="year" value="{{ $bulanIni->year }}">
        <input type="hidden" name="month" id="month" value="{{ $bulanIni->month }}">
        <button class="btn-utama">Buat periode</button>
    </form>
</div>

<div class="overflow-hidden kartu">
    <div class="tabel-bungkus">
    <table class="tabel">
        <thead>
            <tr>
                <th >Kode</th>
                <th >Periode kerja</th>
                <th >Dibayar</th>
                <th >Status</th>
                <th >Versi</th>
                <th ></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($periods as $p)
                <tr >
                    <td class="font-mono text-xs">{{ $p->code }}</td>
                    <td >{{ $p->label() }}</td>
                    <td class="text-slate-500">{{ $p->pay_date->translatedFormat('d M Y') }}</td>
                    <td ><x-status-badge :warna="$p->status->color()" :label="$p->status->label()" /></td>
                    <td class="text-slate-500">{{ $p->runs->max('version') ?? '—' }}</td>
                    <td class="text-right">
                        <a href="{{ route('manajer.payroll.show', $p) }}" class="font-medium text-slate-700 hover:underline">Buka</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">Belum ada periode payroll.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
