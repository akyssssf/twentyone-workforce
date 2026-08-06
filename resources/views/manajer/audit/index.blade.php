@extends('layouts.app')
@section('title', 'Audit')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">Audit Log</h1>
        <p class="mt-1 text-sm text-slate-500">
            Bawaannya hanya tindakan manusia. Perubahan otomatis oleh proses terjadwal disembunyikan
            supaya yang penting tidak tenggelam.
        </p>
    </div>

    <form method="GET" class="flex items-end gap-2">
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="sistem" value="1" @checked(request()->boolean('sistem'))
                   onchange="this.form.submit()" class="rounded border-slate-300">
            Tampilkan aksi sistem
        </label>
    </form>
</div>

<div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-2 font-medium">Waktu</th>
                <th class="px-4 py-2 font-medium">Aktor</th>
                <th class="px-4 py-2 font-medium">Aksi</th>
                <th class="px-4 py-2 font-medium">Objek</th>
                <th class="px-4 py-2 font-medium">Keterangan</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($logs as $log)
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-2 whitespace-nowrap text-slate-500">{{ $log->created_at?->translatedFormat('d M H:i') }}</td>
                    <td class="px-4 py-2">
                        {{ $log->actor_name }}
                        @if ($log->actor_type === 'system')
                            <span class="ml-1 rounded bg-slate-100 px-1 text-[10px] text-slate-500">otomatis</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 font-mono text-xs">{{ $log->action }}</td>
                    <td class="px-4 py-2 text-slate-500">{{ class_basename($log->auditable_type ?? '') }} {{ $log->auditable_id }}</td>
                    <td class="px-4 py-2 text-xs text-slate-500">
                        @if ($log->context)
                            {{ collect($log->context)->map(fn ($v, $k) => "$k: " . (is_scalar($v) ? $v : json_encode($v)))->implode(', ') }}
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">Belum ada catatan.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $logs->links() }}</div>
@endsection
