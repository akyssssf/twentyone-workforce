@props(['pesan' => 'Belum ada data.'])

{{-- Keadaan kosong yang menjelaskan, bukan sel tabel bergaris yang menggantung. --}}
<div {{ $attributes->merge(['class' => 'px-4 py-10 text-center']) }}>
    <p class="text-sm text-slate-400">{{ $pesan }}</p>
    @if (isset($slot) && trim($slot))
        <div class="mt-3">{{ $slot }}</div>
    @endif
</div>
