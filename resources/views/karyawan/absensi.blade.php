@extends('layouts.app')
@section('title', 'Absensi Saya')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-xl font-semibold tracking-tight sm:text-2xl">Absensi Saya</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $bulan->translatedFormat('F Y') }}</p>
    </div>
    <form method="GET" class="flex items-end gap-2">
        <input type="month" name="bulan" value="{{ $bulan->format('Y-m') }}"
               class="kolom">
        <button class="btn-utama">Lihat</button>
    </form>
</div>

<div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
    <x-kartu label="Hadir" :nilai="$ringkasan['hadir']" warna="text-emerald-600" />
    <x-kartu label="Terlambat" :nilai="$ringkasan['telat']" warna="text-amber-600" />
    <x-kartu label="Pulang Cepat" :nilai="$ringkasan['pulang_cepat']" warna="text-orange-600" />
    <x-kartu label="Alpha" :nilai="$ringkasan['alpha']" warna="text-red-600" />
    <x-kartu label="Total telat" :nilai="\App\Support\Durasi::menit($ringkasan['total_telat_menit'])" />
    <x-kartu label="Lembur" :nilai="\App\Support\Durasi::menit($ringkasan['lembur_menit'])" warna="text-indigo-600" />
</div>

{{-- Di ponsel, 7 kolom dipaksa muat jadi tabel cuma bikin geser ke samping
     tanpa petunjuk kalau bisa digeser — orangnya cuma lihat kolom
     "Pulang" kepotong dan mengira datanya hilang. Jadi di layar sempit
     ganti jadi kartu satu per hari (semua info tersusun vertikal, tidak
     ada yang kepotong); tabel penuh baru muncul dari layar sedang ke atas
     tempat 7 kolom memang muat wajar. --}}
<div class="space-y-2 sm:hidden">
    @forelse ($attendances as $a)
        <div class="kartu p-3">
            <div class="flex items-center justify-between gap-2">
                <span class="font-medium">{{ $a->work_date->translatedFormat('D, d M') }}</span>
                <x-status-badge :warna="$a->status->color()" :label="$a->status->label()" />
            </div>
            <div class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1.5 text-sm">
                <div><span class="text-slate-400">Shift</span><br>{{ $a->shift?->name ?? '—' }}</div>
                <div><span class="text-slate-400">Jadwal</span><br>{{ $a->scheduled_in?->format('H:i') ?? '—' }}</div>
                <div><span class="text-slate-400">Masuk</span><br>{{ $a->check_in_at?->format('H:i') ?? '—' }}</div>
                <div><span class="text-slate-400">Pulang</span><br>
                    {{ $a->check_out_at?->format('H:i') ?? '—' }}
                    @if ($a->check_out_at && ! $a->check_out_at->isSameDay($a->work_date))
                        <span class="text-xs text-slate-400">(+1 hari)</span>
                    @endif
                </div>
            </div>
            @if ($a->late_minutes > 0 || $a->has_adjustment)
                <div class="mt-2 flex items-center gap-2 border-t border-slate-100 pt-2 text-xs">
                    @if ($a->late_minutes > 0)
                        <span class="font-medium text-amber-700">Telat {{ \App\Support\Durasi::menit($a->late_minutes) }}</span>
                    @endif
                    @if ($a->has_adjustment)
                        <span class="text-slate-400">dikoreksi</span>
                    @endif
                </div>
            @endif
        </div>
    @empty
        <x-kosong pesan="Belum ada data." />
    @endforelse
</div>

<div class="hidden overflow-hidden kartu sm:block">
    <div class="tabel-bungkus">
        <table class="tabel">
            <thead>
                <tr>
                    <th >Tanggal</th>
                    <th >Shift</th>
                    <th >Jadwal</th>
                    <th >Masuk</th>
                    <th >Pulang</th>
                    <th >Telat</th>
                    <th >Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($attendances as $a)
                    <tr >
                        <td >{{ $a->work_date->translatedFormat('D, d M') }}</td>
                        <td class="text-slate-500">{{ $a->shift?->name ?? '—' }}</td>
                        <td class="text-slate-500">{{ $a->scheduled_in?->format('H:i') ?? '—' }}</td>
                        <td >{{ $a->check_in_at?->format('H:i') ?? '—' }}</td>
                        <td >
                            {{ $a->check_out_at?->format('H:i') ?? '—' }}
                            @if ($a->check_out_at && ! $a->check_out_at->isSameDay($a->work_date))
                                <span class="text-xs text-slate-400">(+1 hari)</span>
                            @endif
                        </td>
                        <td class="{{ $a->late_minutes > 0 ? 'text-amber-700' : 'text-slate-400' }}">
                            {{ \App\Support\Durasi::menit($a->late_minutes) }}
                        </td>
                        <td >
                            <x-status-badge :warna="$a->status->color()" :label="$a->status->label()" />
                            @if ($a->has_adjustment)<span class="ml-1 text-xs text-slate-400">dikoreksi</span>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-slate-400">Belum ada data.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<p class="mt-4 text-sm text-slate-500">
    Ada yang salah? <a href="{{ route('karyawan.pengajuan.create', 'correction') }}" class="font-medium text-slate-700 underline">Ajukan koreksi absensi</a>.
</p>
@endsection
