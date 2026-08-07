@extends('layouts.app')
@section('title', 'Roster')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-xl font-semibold tracking-tight sm:text-2xl">Roster Bulanan</h1>
        <p class="mt-1 text-sm text-slate-500">
            Jadwal bulan depan biasanya disusun tanggal 20–25.
        </p>
    </div>

    <form method="POST" action="{{ route('manajer.roster.store') }}" class="flex items-end gap-2">
        @csrf
        <input type="hidden" name="period_year" value="{{ $bulanDepan->year }}">
        <input type="hidden" name="period_month" value="{{ $bulanDepan->month }}">
        <button class="btn-utama">
            Buat roster {{ $bulanDepan->translatedFormat('F Y') }}
        </button>
    </form>
</div>

<div class="overflow-hidden kartu">
    <table class="tabel">
        <thead>
            <tr>
                <th >Periode</th>
                <th >Status</th>
                <th >Terbit</th>
                <th ></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rosters as $r)
                <tr >
                    <td >{{ $r->label() }}</td>
                    <td >
                        <x-status-badge :warna="$r->status->value === 'published' ? 'emerald' : ($r->status->value === 'locked' ? 'slate' : 'amber')" :label="$r->status->label()" />
                    </td>
                    <td class="text-slate-500">{{ $r->published_at?->translatedFormat('d M Y H:i') ?? '—' }}</td>
                    <td class="text-right">
                        <a href="{{ route('manajer.roster.show', $r) }}" class="font-medium text-slate-700 hover:underline">Buka</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">Belum ada roster.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
