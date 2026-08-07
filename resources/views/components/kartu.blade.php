@props([
    'label',
    'nilai',
    'warna' => 'slate',
    'keterangan' => null,
])

@php
    // Aksen warna dipakai tipis: satu titik dan angkanya. Kartu penuh warna
    // membuat sembilan metrik di dashboard berteriak bersamaan dan tidak ada
    // yang menonjol.
    $teks = [
        'emerald' => 'text-emerald-600',
        'amber' => 'text-amber-600',
        'orange' => 'text-orange-600',
        'red' => 'text-red-600',
        'sky' => 'text-sky-600',
        'violet' => 'text-violet-600',
        'indigo' => 'text-indigo-600',
        'slate' => 'text-slate-900',
    ][$warna] ?? 'text-slate-900';

    $titik = [
        'emerald' => 'bg-emerald-500',
        'amber' => 'bg-amber-500',
        'orange' => 'bg-orange-500',
        'red' => 'bg-red-500',
        'sky' => 'bg-sky-500',
        'violet' => 'bg-violet-500',
        'indigo' => 'bg-indigo-500',
        'slate' => 'bg-slate-400',
    ][$warna] ?? 'bg-slate-400';
@endphp

<div {{ $attributes->merge(['class' => 'kartu px-3.5 py-3 sm:px-4']) }}>
    <div class="flex items-center gap-1.5">
        <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $titik }}"></span>
        <span class="truncate text-[11px] font-medium uppercase tracking-wide text-slate-500">{{ $label }}</span>
    </div>
    <div class="mt-1 text-2xl font-semibold tabular-nums {{ $teks }} sm:text-[28px]">{{ $nilai }}</div>
    @if ($keterangan)
        <div class="mt-0.5 text-[11px] text-slate-400">{{ $keterangan }}</div>
    @endif
</div>
