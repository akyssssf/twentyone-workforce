@props(['label', 'nilai', 'warna' => 'text-slate-900', 'keterangan' => null])

<div class="rounded-xl border border-slate-200 bg-white p-4">
    <div class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</div>
    <div class="mt-1 text-2xl font-semibold {{ $warna }}">{{ $nilai }}</div>
    @if ($keterangan)
        <div class="mt-0.5 text-xs text-slate-400">{{ $keterangan }}</div>
    @endif
</div>
