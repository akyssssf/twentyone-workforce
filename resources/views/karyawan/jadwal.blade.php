@extends('layouts.app')
@section('title', 'Jadwal Saya')

@section('content')

<x-judul-halaman judul="Jadwal Saya" :keterangan="$bulan->translatedFormat('F Y')">
    <x-slot:aksi>
        <form method="GET" class="flex items-end gap-2">
            <input type="month" name="bulan" value="{{ $bulan->format('Y-m') }}" class="kolom w-auto">
            <button class="btn-utama">Lihat</button>
        </form>
    </x-slot:aksi>
</x-judul-halaman>

@if (! $roster)
    <div class="pemberitahuan border-sky-200 bg-sky-50 text-sky-900">
        Jadwal {{ $bulan->translatedFormat('F Y') }} belum diterbitkan admin.
    </div>
@else
    {{-- Di layar kecil, tujuh kolom hanya menyisakan sekitar 45px per sel —
         tidak cukup untuk menulis "Shift Pagi 09:00", dan teks yang dipaksa
         masuk terpotong di tengah kata. Jadi di ponsel selnya cuma memuat
         KODE shift berlatar warna; nama lengkapnya muncul mulai layar sedang,
         dan keterangan di bawah kalender yang menjelaskan artinya. --}}
    <div class="kartu overflow-hidden p-2 sm:p-4">
        <div class="grid grid-cols-7 gap-1 sm:gap-2">
            {{-- Dua huruf, bukan satu: Senin, Selasa, dan Sabtu sama-sama
                 berawal "S", dan kalender yang kolomnya tidak bisa dibedakan
                 lebih buruk daripada tidak ada kalender. --}}
            @foreach (['Sen' => 'Sn', 'Sel' => 'Sl', 'Rab' => 'Rb', 'Kam' => 'Km',
                       'Jum' => 'Jm', 'Sab' => 'Sb', 'Min' => 'Mg'] as $panjang => $pendek)
                <div class="pb-1 text-center text-[10px] font-medium text-slate-500 sm:text-xs">
                    <span class="sm:hidden">{{ $pendek }}</span>
                    <span class="hidden sm:inline">{{ $panjang }}</span>
                </div>
            @endforeach

            @php
                $awal = $bulan->copy()->startOfMonth();
                $offset = $awal->dayOfWeekIso - 1;
            @endphp

            @for ($i = 0; $i < $offset; $i++)
                <div></div>
            @endfor

            @for ($d = $awal->copy(); $d->month === $bulan->month; $d->addDay())
                @php $a = $assignments->get($d->toDateString()); @endphp

                <a href="{{ route('karyawan.jadwal', ['bulan' => $bulan->format('Y-m'), 'tanggal' => $d->toDateString()]) }}#rincian"
                   @class([
                       'flex min-h-14 flex-col rounded-lg border p-1 transition hover:border-slate-400 sm:min-h-20 sm:rounded-xl sm:p-1.5',
                       'border-slate-900 ring-2 ring-slate-900' => $pilih && $d->isSameDay($pilih),
                       'border-slate-900' => $d->isToday() && ! ($pilih && $d->isSameDay($pilih)),
                       'border-slate-200' => ! $d->isToday() && ! ($pilih && $d->isSameDay($pilih)),
                   ])>
                    <div @class([
                        'text-center text-[10px] font-medium sm:text-left sm:text-xs',
                        'text-slate-900' => $d->isToday(),
                        'text-slate-400' => ! $d->isToday(),
                    ])>{{ $d->day }}</div>

                    @if ($a?->shift)
                        <div class="mt-auto rounded px-0.5 py-0.5 text-center text-[10px] font-semibold leading-tight text-white sm:px-1.5 sm:py-1 sm:text-left sm:text-[11px]"
                             style="background: {{ $a->shift->color ?? '#475569' }}"
                             title="{{ $a->shift->name }}{{ $a->shift->show_hours ? ' '.substr($a->mulaiEfektif(), 0, 5).'–'.substr($a->selesaiEfektif(), 0, 5) : '' }}{{ $a->pakaiJamKhusus() ? ' (jam khusus)' : '' }}">
                            <span class="sm:hidden">{{ mb_strtoupper(mb_substr($a->shift->code ?? '?', 0, 1)) }}</span>
                            <span class="hidden sm:block">{{ $a->shift->name }}</span>
                            @if ($a->shift->show_hours)
                                <span class="hidden font-normal opacity-90 sm:block">
                                    {{ substr($a->mulaiEfektif(), 0, 5) }}@if ($a->pakaiJamKhusus())*@endif
                                </span>
                            @endif
                        </div>
                    @elseif ($a)
                        <div class="mt-auto rounded bg-slate-100 px-0.5 py-0.5 text-center text-[10px] leading-tight text-slate-500 sm:px-1.5 sm:py-1 sm:text-left sm:text-[11px]"
                             title="{{ $a->status->label() }}">
                            <span class="sm:hidden">&middot;</span>
                            <span class="hidden sm:inline">{{ $a->status->label() }}</span>
                        </div>
                    @endif
                </a>
            @endfor
        </div>
    </div>

    <p class="mt-2 text-center text-xs text-slate-400 sm:text-left">
        Ketuk tanggal untuk melihat siapa saja yang bertugas hari itu.
    </p>

    {{-- Keterangan warna. Wajib ada karena di ponsel selnya cuma satu huruf. --}}
    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-500">
        @foreach ($shifts as $shift)
            <span class="flex items-center gap-1.5">
                <span class="flex h-4 w-4 items-center justify-center rounded text-[9px] font-bold text-white"
                      style="background: {{ $shift->color ?? '#475569' }}">
                    {{ mb_strtoupper(mb_substr($shift->code ?? '?', 0, 1)) }}
                </span>
                {{ $shift->name }}
                @if ($shift->show_hours)
                    {{ substr($shift->start_time, 0, 5) }}–{{ substr($shift->end_time, 0, 5) }}
                @endif
            </span>
        @endforeach
        <span class="flex items-center gap-1.5">
            <span class="flex h-4 w-4 items-center justify-center rounded bg-slate-100 text-slate-500">&middot;</span>
            Libur
        </span>
    </div>

    {{-- Rincian tanggal yang diketuk.

         Ditaruh di bawah kalender, bukan di jendela mengambang, supaya di
         ponsel tidak menutupi kalendernya sendiri — orang sering
         membandingkan beberapa tanggal berturut-turut. --}}
    @if ($pilih)
        <div id="rincian" class="kartu mt-5">
            <div class="kartu-judul">
                <div>
                    <h2 class="font-semibold">{{ $pilih->translatedFormat('l, d F Y') }}</h2>
                    <p class="text-xs text-slate-500">Yang bertugas hari itu</p>
                </div>
                <a href="{{ route('karyawan.jadwal', ['bulan' => $bulan->format('Y-m')]) }}"
                   class="text-sm text-slate-500 transition hover:text-slate-900">Tutup</a>
            </div>

            @forelse ($shifts as $shift)
                @php $petugas = $rekanHariItu->get($shift->id, collect()); @endphp
                <div class="border-b border-slate-50 px-4 py-3 last:border-0 sm:px-5">
                    <div class="mb-2 flex items-center justify-between">
                        <div class="flex items-center gap-2 text-sm font-medium">
                            <span class="h-2.5 w-2.5 rounded-full" style="background: {{ $shift->color ?? '#475569' }}"></span>
                            {{ $shift->name }}
                            @if ($shift->show_hours)
                                @php $khusus = $petugas->first(fn ($a) => $a->pakaiJamKhusus()); @endphp
                                <span class="font-normal {{ $khusus ? 'text-amber-700' : 'text-slate-500' }}">
                                    {{ substr($khusus?->mulaiEfektif() ?? $shift->start_time, 0, 5) }}–{{ substr($khusus?->selesaiEfektif() ?? $shift->end_time, 0, 5) }}
                                    @if ($khusus)<span class="text-xs">(jam khusus)</span>@endif
                                </span>
                            @endif
                        </div>
                        <span class="text-xs text-slate-500">{{ $petugas->count() }} orang</span>
                    </div>

                    @if ($petugas->isEmpty())
                        <p class="text-sm text-slate-400">Tidak ada yang dijadwalkan.</p>
                    @else
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($petugas->sortBy(fn ($a) => $a->employee?->name) as $a)
                                <span @class([
                                    'inline-flex items-center gap-1.5 rounded-full py-1 pl-1 pr-2.5 text-xs',
                                    'bg-slate-900 text-white' => $a->employee_id === $employee->id,
                                    'bg-slate-100' => $a->employee_id !== $employee->id,
                                ])>
                                    <x-avatar :employee="$a->employee" ukuran="sm" />
                                    <span class="h-2 w-2 rounded-full" style="background: {{ $a->division?->color ?? '#94a3b8' }}"></span>
                                    {{ $a->employee_id === $employee->id ? 'Anda' : $a->employee?->name }}
                                    <span @class(['text-slate-300' => $a->employee_id === $employee->id, 'text-slate-400' => $a->employee_id !== $employee->id])>
                                        {{ $a->division?->name }}
                                    </span>
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <x-kosong pesan="Belum ada shift aktif." />
            @endforelse
        </div>
    @endif
@endif

@endsection
