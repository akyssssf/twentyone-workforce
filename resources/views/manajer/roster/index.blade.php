@extends('layouts.app')
@section('title', 'Roster')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">Roster Bulanan</h1>
        <p class="mt-1 text-sm text-slate-500">
            Jadwal bulan depan biasanya disusun tanggal 20–25.
        </p>
    </div>

    <form method="POST" action="{{ route('manajer.roster.store') }}" class="flex items-end gap-2">
        @csrf
        <input type="hidden" name="period_year" value="{{ $bulanDepan->year }}">
        <input type="hidden" name="period_month" value="{{ $bulanDepan->month }}">
        <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
            Buat roster {{ $bulanDepan->translatedFormat('F Y') }}
        </button>
    </form>
</div>

<div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-2 font-medium">Periode</th>
                <th class="px-4 py-2 font-medium">Status</th>
                <th class="px-4 py-2 font-medium">Terbit</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rosters as $r)
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-2 font-medium">{{ $r->label() }}</td>
                    <td class="px-4 py-2">
                        <x-status-badge :warna="$r->status->value === 'published' ? 'emerald' : ($r->status->value === 'locked' ? 'slate' : 'amber')" :label="$r->status->label()" />
                    </td>
                    <td class="px-4 py-2 text-slate-500">{{ $r->published_at?->translatedFormat('d M Y H:i') ?? '—' }}</td>
                    <td class="px-4 py-2 text-right">
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
