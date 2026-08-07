@props([
    'employee' => null,
    'ukuran' => 'md',
    'bisaDiklik' => false,
])

@php
    $kelas = [
        'xs' => 'h-6 w-6 text-[9px]',
        'sm' => 'h-8 w-8 text-[10px]',
        'md' => 'h-10 w-10 text-xs',
        'lg' => 'h-16 w-16 text-lg',
        'xl' => 'h-20 w-20 text-xl',
    ][$ukuran] ?? 'h-10 w-10 text-xs';

    $foto = $employee?->avatarUrl();
    $nama = $employee?->name ?? '—';
@endphp

@if ($foto)
    @if ($bisaDiklik)
        <a href="{{ $foto }}" target="_blank" rel="noopener noreferrer" title="{{ $nama }}">
            <img src="{{ $foto }}" alt="{{ $nama }}"
                 {{ $attributes->merge(['class' => "{$kelas} shrink-0 rounded-full object-cover ring-2 ring-slate-200 transition hover:scale-105"]) }}>
        </a>
    @else
        <img src="{{ $foto }}" alt="{{ $nama }}" title="{{ $nama }}"
             {{ $attributes->merge(['class' => "{$kelas} shrink-0 rounded-full object-cover ring-2 ring-slate-200"]) }}>
    @endif
@else
    {{-- Foto belum ada: inisial, bukan ikon kosong. Wajah orang yang belum
         pernah scan tetap harus bisa dibedakan satu sama lain di daftar. --}}
    <div title="{{ $nama }}"
         {{ $attributes->merge(['class' => "{$kelas} flex shrink-0 items-center justify-center rounded-full bg-slate-200 font-semibold text-slate-600"]) }}>
        {{ $employee?->initials() ?? '?' }}
    </div>
@endif
