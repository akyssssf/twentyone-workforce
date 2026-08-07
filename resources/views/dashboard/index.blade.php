@extends('layouts.app')

@section('title', 'Hari Ini')

@section('content')

<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-slate-500">{{ $tanggal->translatedFormat('l, d F Y') }}</p>

    <form method="GET" class="flex items-center gap-2">
        <input type="date" name="tanggal" value="{{ $tanggal->toDateString() }}" class="kolom w-auto">
        <button class="btn-utama">Lihat</button>
    </form>
</div>

{{-- Empat kartu ringkasan.

     Masing-masing: satu angka besar, dua angka pembanding, lalu satu baris
     penutup di bawah garis. Bentuk ini dipilih karena angka tunggal tanpa
     pembanding tidak memberi tahu apa pun — "6 alpha" baru punya arti setelah
     terlihat berapa yang seharusnya masuk. --}}
<div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">

    <div class="kartu p-4">
        <p class="text-sm text-slate-500">Kehadiran</p>
        <p class="mt-1 text-3xl font-semibold tabular-nums">{{ $ringkasan['hadir'] }}</p>
        <div class="mt-3 space-y-1 text-sm">
            <div class="flex items-center justify-between">
                <span class="text-slate-500">Terlambat</span>
                <span class="font-medium text-amber-600 tabular-nums">{{ $ringkasan['terlambat'] }}</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-slate-500">Pulang cepat</span>
                <span class="font-medium text-orange-600 tabular-nums">{{ $ringkasan['pulang_cepat'] }}</span>
            </div>
        </div>
        <div class="mt-3 border-t border-slate-100 pt-2.5 text-sm text-slate-500">
            Dari {{ $ringkasan['karyawan'] }} karyawan yang diabsen
        </div>
    </div>

    <div class="kartu p-4">
        <p class="text-sm text-slate-500">Tidak masuk</p>
        <p class="mt-1 text-3xl font-semibold tabular-nums {{ $ringkasan['alpha'] > 0 ? 'text-red-600' : '' }}">
            {{ $ringkasan['alpha'] }}
        </p>
        <div class="mt-3 space-y-1 text-sm">
            <div class="flex items-center justify-between">
                <span class="text-slate-500">Izin / Sakit</span>
                <span class="font-medium tabular-nums">{{ $ringkasan['izin'] + $ringkasan['sakit'] }}</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-slate-500">Cuti</span>
                <span class="font-medium tabular-nums">{{ $ringkasan['cuti'] }}</span>
            </div>
        </div>
        <div class="mt-3 border-t border-slate-100 pt-2.5 text-sm text-slate-500">
            Libur terjadwal {{ $ringkasan['libur'] }} orang
        </div>
    </div>

    <div class="kartu p-4">
        <p class="text-sm text-slate-500">Pengajuan</p>
        <p class="mt-1 text-3xl font-semibold tabular-nums {{ $jumlahPending > 0 ? 'text-amber-600' : '' }}">
            {{ $jumlahPending }}
        </p>
        <div class="mt-3 space-y-1 text-sm">
            <div class="flex items-center justify-between">
                <span class="text-slate-500">Menunggu Anda</span>
                <span class="font-medium tabular-nums">{{ $jumlahPending }}</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-slate-500">Menunggu pengganti</span>
                <span class="font-medium tabular-nums">{{ $menungguPengganti }}</span>
            </div>
        </div>
        <a href="{{ route('manajer.pengajuan.index') }}"
           class="mt-3 block border-t border-slate-100 pt-2.5 text-sm font-medium text-slate-700 transition hover:text-slate-900">
            Buka daftar pengajuan &rarr;
        </a>
    </div>

    <div class="kartu p-4">
        <p class="text-sm text-slate-500">Lembur</p>
        <p class="mt-1 text-3xl font-semibold tabular-nums {{ $ringkasan['lembur'] > 0 ? 'text-indigo-600' : '' }}">
            {{ $ringkasan['lembur'] }}
        </p>
        <div class="mt-3 space-y-1 text-sm">
            <div class="flex items-center justify-between">
                <span class="text-slate-500">Sudah diaktifkan</span>
                <span class="font-medium tabular-nums">{{ $lemburHariIni->whereNotNull('activated_at')->count() }}</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-slate-500">Belum pakai kode</span>
                <span class="font-medium tabular-nums {{ $lemburHariIni->whereNull('activated_at')->count() > 0 ? 'text-amber-600' : '' }}">
                    {{ $lemburHariIni->whereNull('activated_at')->count() }}
                </span>
            </div>
        </div>
        <div class="mt-3 border-t border-slate-100 pt-2.5 text-sm text-slate-500">
            @if ($periodeBerjalan)
                Periode {{ $periodeBerjalan->code }} &middot; {{ $periodeBerjalan->status->label() }}
            @else
                Periode payroll belum dibuat
            @endif
        </div>
    </div>
</div>

@if ($pinAsing->isNotEmpty())
    <div class="pemberitahuan mb-5 border-amber-200 bg-amber-50 text-amber-900">
        <strong>PIN belum terdaftar:</strong> {{ $pinAsing->implode(', ') }}.
        Scan-nya tersimpan aman, tapi belum masuk rekap siapa pun sampai PIN itu dipetakan ke seorang karyawan.
    </div>
@endif

@if ($belumDihitung)
    <div class="pemberitahuan mb-5 border-sky-200 bg-sky-50 text-sky-900">
        Belum ada rekap untuk tanggal ini. Jalankan
        <code class="rounded bg-sky-100 px-1">php artisan attendance:compute</code>
        atau tunggu proses terjadwal berikutnya.
    </div>
@endif

<div class="grid gap-5 lg:grid-cols-3">

    {{-- Tren + roster --}}
    <div class="space-y-5 lg:col-span-2">

        <div class="kartu">
            <div class="kartu-judul">
                <h2 class="font-semibold">Tren 7 Hari</h2>
                <div class="flex items-center gap-3 text-xs text-slate-500">
                    <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>Hadir</span>
                    <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-amber-500"></span>Telat</span>
                    <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-red-500"></span>Alpha</span>
                </div>
            </div>

            <div class="kartu-isi">
                @php $puncak = max(1, collect($tren)->max(fn ($t) => $t['hadir'] + $t['alpha'])); @endphp

                {{-- Tinggi batang dinyatakan dalam persen, jadi setiap induknya
                     harus punya tinggi pasti sampai ke atas. Tanpa itu persennya
                     tidak punya acuan dan batangnya tidak muncul sama sekali —
                     bukan error, cuma kosong. --}}
                <div class="flex h-40 justify-between gap-2 sm:gap-3">
                    @foreach ($tren as $t)
                        <div class="flex h-full flex-1 flex-col items-center gap-1.5">
                            <div class="flex w-full flex-1 items-end justify-center gap-0.5 sm:gap-1">
                                @foreach ([
                                    ['nilai' => $t['hadir'], 'warna' => 'bg-emerald-500'],
                                    ['nilai' => $t['telat'], 'warna' => 'bg-amber-500'],
                                    ['nilai' => $t['alpha'], 'warna' => 'bg-red-500'],
                                ] as $batang)
                                    <div class="w-full max-w-3 rounded-t {{ $batang['warna'] }} transition-all"
                                         style="height: {{ $batang['nilai'] > 0 ? max(4, round($batang['nilai'] / $puncak * 100)) : 2 }}%"
                                         title="{{ $batang['nilai'] }}"></div>
                                @endforeach
                            </div>
                            <span @class([
                                'text-[10px] sm:text-xs',
                                'font-semibold text-slate-900' => $t['tanggal']->isSameDay($tanggal),
                                'text-slate-400' => ! $t['tanggal']->isSameDay($tanggal),
                            ])>{{ $t['tanggal']->translatedFormat('D') }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Roster hari ini --}}
        <div class="kartu">
            <div class="kartu-judul"><h2 class="font-semibold">Roster Hari Ini</h2></div>

            @forelse ($shifts as $shift)
                @php $petugas = $jadwal->get($shift->id, collect()); @endphp
                <div class="border-b border-slate-50 px-4 py-3 last:border-0 sm:px-5">
                    <div class="mb-2 flex items-center justify-between">
                        <div class="flex items-center gap-2 text-sm font-medium">
                            <span class="h-2.5 w-2.5 rounded-full" style="background: {{ $shift->color ?? '#475569' }}"></span>
                            {{ $shift->name }}
                            <span class="font-normal text-slate-500">
                                {{ substr($shift->start_time, 0, 5) }}–{{ substr($shift->end_time, 0, 5) }}
                            </span>
                        </div>
                        <span class="text-xs text-slate-500">{{ $petugas->count() }} orang</span>
                    </div>

                    @if ($petugas->isEmpty())
                        <p class="text-sm text-slate-400">Belum ada yang dijadwalkan.</p>
                    @else
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($petugas->sortBy(fn ($a) => $a->employee?->name) as $a)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 py-1 pl-1 pr-2.5 text-xs">
                                    <x-avatar :employee="$a->employee" ukuran="sm" />
                                    <span class="h-2 w-2 rounded-full" style="background: {{ $a->division?->color ?? '#94a3b8' }}"></span>
                                    {{ $a->employee?->name }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <x-kosong pesan="Belum ada shift aktif." />
            @endforelse
        </div>
    </div>

    {{-- Pengajuan --}}
    <div class="kartu self-start">
        <div class="kartu-judul">
            <h2 class="font-semibold">Pengajuan Pending</h2>
            @if ($jumlahPending > 0)
                <x-status-badge warna="amber" :label="$jumlahPending" />
            @endif
        </div>

        <div class="divide-y divide-slate-50">
            @forelse ($pengajuanPending as $p)
                <a href="{{ route('manajer.pengajuan.show', $p) }}"
                   class="flex items-start gap-3 px-4 py-3 transition hover:bg-slate-50 sm:px-5">
                    <x-avatar :employee="$p->employee" ukuran="sm" />
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium">{{ $p->employee?->name }}</div>
                        <div class="truncate text-xs text-slate-500">{{ $p->type->label() }} &middot; {{ $p->code }}</div>
                    </div>
                </a>
            @empty
                <x-kosong pesan="Tidak ada yang menunggu." />
            @endforelse
        </div>

        @if ($jumlahPending > 0)
            <a href="{{ route('manajer.pengajuan.index') }}"
               class="block border-t border-slate-100 px-4 py-3 text-center text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                Lihat semua
            </a>
        @endif
    </div>
</div>

{{-- Rekap absensi --}}
<div class="kartu mt-5 overflow-hidden">
    <div class="kartu-judul"><h2 class="font-semibold">Absensi Hari Ini</h2></div>

    <div class="tabel-bungkus">
        <table class="tabel">
            <thead>
                <tr>
                    <th>Nama</th>
                    <th>Shift</th>
                    <th>Jadwal</th>
                    <th>Masuk</th>
                    <th>Pulang</th>
                    <th>Telat</th>
                    <th>Plg Cepat</th>
                    <th>Lembur</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rekap as $a)
                    <tr>
                        <td>
                            <div class="flex items-center gap-2.5">
                                <x-avatar :employee="$a->employee" ukuran="sm" :bisa-diklik="true" />
                                <span class="font-medium whitespace-nowrap">{{ $a->employee?->name ?? '—' }}</span>
                            </div>
                        </td>
                        <td class="whitespace-nowrap text-slate-500">{{ $a->shift?->name ?? '—' }}</td>
                        <td class="tabular-nums text-slate-500">{{ $a->scheduled_in?->format('H:i') ?? '—' }}</td>
                        <td class="tabular-nums">
                            @if ($a->check_in_at)
                                @if ($a->firstLog?->photo_url)
                                    <a href="{{ $a->firstLog->photo_url }}" target="_blank" rel="noopener noreferrer"
                                       class="inline-flex items-center gap-1 hover:underline" title="Lihat bukti foto scan masuk">
                                        {{ $a->check_in_at->format('H:i') }}
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5 text-slate-400">
                                            <path d="M6.5 3a1 1 0 00-.894.553L5.118 4.5H3.5A1.5 1.5 0 002 6v9a1.5 1.5 0 001.5 1.5h13A1.5 1.5 0 0018 15V6a1.5 1.5 0 00-1.5-1.5h-1.618l-.488-.947A1 1 0 0013.5 3h-7zM10 14a3.5 3.5 0 110-7 3.5 3.5 0 010 7z"/>
                                        </svg>
                                    </a>
                                @else
                                    {{ $a->check_in_at->format('H:i') }}
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td class="tabular-nums">
                            @if ($a->check_out_at)
                                @if ($a->lastLog?->photo_url)
                                    <a href="{{ $a->lastLog->photo_url }}" target="_blank" rel="noopener noreferrer"
                                       class="inline-flex items-center gap-1 hover:underline" title="Lihat bukti foto scan pulang">
                                        {{ $a->check_out_at->format('H:i') }}
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5 text-slate-400">
                                            <path d="M6.5 3a1 1 0 00-.894.553L5.118 4.5H3.5A1.5 1.5 0 002 6v9a1.5 1.5 0 001.5 1.5h13A1.5 1.5 0 0018 15V6a1.5 1.5 0 00-1.5-1.5h-1.618l-.488-.947A1 1 0 0013.5 3h-7zM10 14a3.5 3.5 0 110-7 3.5 3.5 0 010 7z"/>
                                        </svg>
                                    </a>
                                @else
                                    {{ $a->check_out_at->format('H:i') }}
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td class="tabular-nums {{ $a->late_minutes > 0 ? 'font-medium text-amber-700' : 'text-slate-400' }}">
                            {{ $a->late_minutes > 0 ? $a->late_minutes.' m' : '—' }}
                        </td>
                        <td class="tabular-nums {{ $a->early_leave_minutes > 0 ? 'font-medium text-orange-700' : 'text-slate-400' }}">
                            {{ $a->early_leave_minutes > 0 ? $a->early_leave_minutes.' m' : '—' }}
                        </td>
                        <td class="tabular-nums {{ $a->overtime_minutes > 0 ? 'font-medium text-indigo-700' : 'text-slate-400' }}">
                            {{ $a->overtime_minutes > 0 ? round($a->overtime_minutes / 60, 1).' j' : '—' }}
                        </td>
                        <td>
                            <x-status-badge :warna="$a->status->color()" :label="$a->status->label()" />
                            @if ($a->has_adjustment)
                                <span class="ml-1 text-xs text-slate-400" title="{{ $a->source_note }}">dikoreksi</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9"><x-kosong pesan="Belum ada data absensi untuk tanggal ini." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Scan mentah: bukti pertama saat ada sengketa jam. --}}
<div class="kartu mt-5 overflow-hidden">
    <div class="kartu-judul">
        <div>
            <h2 class="font-semibold">Aktivitas Scan</h2>
            <p class="text-xs text-slate-500">Termasuk scan pulang shift malam yang jatuh setelah tengah malam.</p>
        </div>
    </div>

    <div class="tabel-bungkus">
        <table class="tabel">
            <thead>
                <tr><th>Waktu</th><th>PIN</th><th>Nama</th><th>Sumber</th><th>Foto</th></tr>
            </thead>
            <tbody>
                @forelse ($scan->take(25) as $log)
                    <tr>
                        <td class="whitespace-nowrap tabular-nums">
                            {{ $log->scanned_at->format('d M H:i:s') }}
                            @if (! $log->scanned_at->isSameDay($tanggal))
                                <span class="text-xs text-slate-400">(+1 hari)</span>
                            @endif
                        </td>
                        <td class="font-mono text-xs">{{ $log->pin }}</td>
                        <td>
                            <div class="flex items-center gap-2.5">
                                @if ($log->employee)
                                    <x-avatar :employee="$log->employee" ukuran="sm" :bisa-diklik="true" />
                                    <span class="whitespace-nowrap">{{ $log->employee->name }}</span>
                                @else
                                    <span class="text-xs text-amber-600">PIN belum terdaftar</span>
                                @endif
                            </div>
                        </td>
                        <td class="text-slate-500">{{ $log->source }}</td>
                        <td>
                            @if ($log->photo_url)
                                <a href="{{ $log->photo_url }}" target="_blank" rel="noopener" class="text-slate-700 underline">lihat</a>
                            @else
                                <span class="text-slate-300">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-kosong pesan="Belum ada scan." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
