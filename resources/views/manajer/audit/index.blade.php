@extends('layouts.app')
@section('title', 'Audit')
@section('lebar', 'max-w-6xl')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-xl font-semibold tracking-tight sm:text-2xl">Audit Log</h1>
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

<div class="overflow-hidden kartu">
    <div class="tabel-bungkus">
    <table class="tabel">
        <thead>
            <tr>
                <th >Waktu</th>
                <th >Aktor</th>
                <th >Aksi</th>
                <th >Objek</th>
                <th >Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($logs as $log)
                <tr >
                    <td class="whitespace-nowrap text-slate-500">{{ $log->created_at?->translatedFormat('d M H:i') }}</td>
                    <td >
                        {{ $log->actor_name }}
                        @if ($log->actor_type === 'system')
                            <span class="ml-1 rounded bg-slate-100 px-1 text-[10px] text-slate-500">otomatis</span>
                        @endif
                    </td>
                    <td class="font-mono text-xs">{{ $log->action }}</td>
                    <td class="text-slate-500">{{ class_basename($log->auditable_type ?? '') }} {{ $log->auditable_id }}</td>
                    <td class="text-xs text-slate-500">
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
</div>

<div class="mt-4">{{ $logs->links() }}</div>
@endsection
