@extends('layouts.app')
@section('title', 'Lembur')

@section('content')
<h1 class="mb-2 text-2xl font-semibold tracking-tight">Lembur</h1>
<p class="mb-6 text-sm text-slate-500">
    Lembur tidak pernah terjadi otomatis. Rencananya disetujui lebih dulu, realisasinya disahkan setelah dikerjakan.
</p>

<div class="mb-6 rounded-xl border border-slate-200 bg-white p-5">
    <h2 class="mb-4 font-semibold">Tugaskan Lembur</h2>
    <form method="POST" action="{{ route('manajer.lembur.store') }}" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium">Karyawan</label>
            <select name="employee_ids[]" multiple size="6" required
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @foreach ($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">Tahan Ctrl/Cmd untuk memilih beberapa orang sekaligus.</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="block text-sm font-medium">Tanggal</label>
                <input type="date" name="work_date" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium">Mulai</label>
                <input type="time" name="planned_start" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium">Selesai</label>
                <input type="time" name="planned_end" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium">Alasan</label>
            <input type="text" name="reason" required minlength="5" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>

        <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
            Tugaskan &amp; setujui
        </button>
    </form>
</div>

<div class="mb-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
    <div class="border-b border-slate-200 px-4 py-3">
        <h2 class="font-semibold">Menunggu Pengesahan Realisasi</h2>
        <p class="text-xs text-slate-500">Yang dibayar min(disetujui, aktual). Menaikkan di atas itu wajib pakai catatan.</p>
    </div>

    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <tbody class="divide-y divide-slate-100">
            @forelse ($records as $r)
                <tr>
                    <td class="px-4 py-3">
                        <div class="font-medium">{{ $r->employee?->name }}</div>
                        <div class="text-xs text-slate-500">
                            {{ $r->work_date->translatedFormat('d M Y') }} &middot; disetujui {{ $r->approved_minutes }} menit
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <form method="POST" action="{{ route('manajer.lembur.confirm', $r) }}" class="flex flex-wrap items-end gap-2">
                            @csrf
                            <div>
                                <label class="block text-xs text-slate-500">Aktual (menit)</label>
                                <input type="number" name="actual_minutes" min="0" value="{{ $r->approved_minutes }}" required
                                       class="mt-0.5 w-24 rounded-lg border border-slate-300 px-2 py-1 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs text-slate-500">Dibayar (menit)</label>
                                <input type="number" name="payable_minutes" min="0" value="{{ $r->approved_minutes }}" required
                                       class="mt-0.5 w-24 rounded-lg border border-slate-300 px-2 py-1 text-sm">
                            </div>
                            <input type="text" name="note" placeholder="Catatan"
                                   class="flex-1 rounded-lg border border-slate-300 px-2 py-1 text-sm">
                            <button class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">Sahkan</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td class="px-4 py-8 text-center text-slate-400">Tidak ada realisasi menunggu.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
    <div class="border-b border-slate-200 px-4 py-3"><h2 class="font-semibold">Lembur Disahkan</h2></div>
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-2 font-medium">Karyawan</th>
                <th class="px-4 py-2 font-medium">Tanggal</th>
                <th class="px-4 py-2 text-right font-medium">Disetujui</th>
                <th class="px-4 py-2 text-right font-medium">Aktual</th>
                <th class="px-4 py-2 text-right font-medium">Dibayar</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($confirmed as $r)
                <tr>
                    <td class="px-4 py-2 font-medium">{{ $r->employee?->name }}</td>
                    <td class="px-4 py-2 text-slate-500">{{ $r->work_date->translatedFormat('d M Y') }}</td>
                    <td class="px-4 py-2 text-right text-slate-500">{{ $r->approved_minutes }} m</td>
                    <td class="px-4 py-2 text-right text-slate-500">{{ $r->actual_minutes }} m</td>
                    <td class="px-4 py-2 text-right font-medium">{{ $r->payable_minutes }} m</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">Belum ada.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
