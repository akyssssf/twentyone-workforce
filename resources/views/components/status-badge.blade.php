@props(['warna' => 'slate', 'label'])

@php
    $kelas = [
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'amber' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        'orange' => 'bg-orange-50 text-orange-700 ring-orange-600/20',
        'red' => 'bg-red-50 text-red-700 ring-red-600/20',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-600/20',
        'indigo' => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
        'slate' => 'bg-slate-100 text-slate-600 ring-slate-500/20',
    ][$warna] ?? 'bg-slate-100 text-slate-600 ring-slate-500/20';
@endphp

<span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset {$kelas}"]) }}>
    {{ $label }}
</span>
