@extends('layouts.app')

@section('title', 'Hari Ini')

@section('content')

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Dashboard</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $tanggal->translatedFormat('l, d F Y') }}</p>
        </div>

        <form method="GET" class="flex items-end gap-2">
            <div>
                <label for="tanggal" class="block text-xs font-medium text-slate-500">Tanggal</label>
                <input id="tanggal" type="date" name="tanggal" value="{{ $tanggal->toDateString() }}"
                       class="mt-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-slate-900 focus:ring-1 focus:ring-slate-900">
            </div>
            <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Lihat</button>
        </form>
    </div>

    {{-- Sembilan angka yang diminta brief. Tiga di antaranya bukan status
         tersimpan, melainkan hitungan dari kolom menit — jadi satu orang bisa
         muncul di beberapa kartu sekaligus, dan itu memang benar. --}}
    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <x-kartu label="Hadir" :nilai="$ringkasan['hadir']" warna="text-emerald-600" />
        <x-kartu label="Terlambat" :nilai="$ringkasan['terlambat']" warna="text-amber-600" />
        <x-kartu label="Pulang Cepat" :nilai="$ringkasan['pulang_cepat']" warna="text-orange-600" />
        <x-kartu label="Alpha" :nilai="$ringkasan['alpha']" warna="text-red-600" />
        <x-kartu label="Lembur" :nilai="$ringkasan['lembur']" warna="text-indigo-600" />
        <x-kartu label="Izin" :nilai="$ringkasan['izin']" warna="text-sky-600" />
        <x-kartu label="Sakit" :nilai="$ringkasan['sakit']" warna="text-violet-600" />
        <x-kartu label="Cuti" :nilai="$ringkasan['cuti']" warna="text-indigo-600" />
        <x-kartu label="Libur" :nilai="$ringkasan['libur']" warna="text-slate-500" />
        <x-kartu label="Karyawan aktif" :nilai="$ringkasan['karyawan']" />
    </div>

    @if ($pinAsing->isNotEmpty())
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <strong>PIN belum terdaftar:</strong> {{ $pinAsing->implode(', ') }}.
            Scan-nya tersimpan aman, tapi belum masuk rekap siapa pun sampai PIN itu
            dipetakan ke seorang karyawan di menu Karyawan.
        </div>
    @endif

    @if ($belumDihitung)
        <div class="mb-6 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
            Belum ada rekap untuk tanggal ini. Jalankan
            <code class="rounded bg-sky-100 px-1">php artisan attendance:compute</code>
            atau tunggu proses terjadwal berikutnya.
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Roster hari ini --}}
        <div class="lg:col-span-2">
            <div class="rounded-xl border border-slate-200 bg-white">
                <div class="border-b border-slate-200 px-4 py-3">
                    <h2 class="font-semibold">Roster Hari Ini</h2>
                </div>

                @forelse ($shifts as $shift)
                    @php $petugas = $jadwal->get($shift->id, collect()); @endphp
                    <div class="border-b border-slate-100 px-4 py-3 last:border-0">
                        <div class="mb-2 flex items-center justify-between">
                            <div class="text-sm font-medium">
                                {{ $shift->name }}
                                <span class="ml-1 text-xs font-normal text-slate-500">
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
                                    <span class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-1 text-xs">
                                        <span class="h-2 w-2 rounded-full" style="background: {{ $a->division?->color ?? '#94a3b8' }}"></span>
                                        {{ $a->employee?->name }}
                                        <span class="text-slate-400">{{ $a->division?->name }}</span>
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="px-4 py-6 text-sm text-slate-400">Belum ada shift aktif.</p>
                @endforelse
            </div>
        </div>

        {{-- Pengajuan pending --}}
        <div>
            <div class="rounded-xl border border-slate-200 bg-white">
                <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                    <h2 class="font-semibold">Pengajuan Pending</h2>
                    @if ($jumlahPending > 0)
                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">{{ $jumlahPending }}</span>
                    @endif
                </div>

                <div class="divide-y divide-slate-100">
                    @forelse ($pengajuanPending as $p)
                        <a href="{{ route('manajer.pengajuan.show', $p) }}" class="block px-4 py-3 hover:bg-slate-50">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-medium">{{ $p->employee?->name }}</div>
                                    <div class="text-xs text-slate-500">{{ $p->type->label() }} · {{ $p->code }}</div>
                                </div>
                                <x-status-badge :warna="$p->status->color()" :label="$p->status->label()" />
                            </div>
                        </a>
                    @empty
                        <p class="px-4 py-6 text-sm text-slate-400">Tidak ada yang menunggu.</p>
                    @endforelse
                </div>

                @if ($jumlahPending > 0)
                    <a href="{{ route('manajer.pengajuan.index') }}" class="block border-t border-slate-200 px-4 py-2.5 text-center text-sm font-medium text-slate-700 hover:bg-slate-50">
                        Lihat semua
                    </a>
                @endif
            </div>
        </div>
    </div>

    {{-- Rekap absensi --}}
    <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-4 py-3">
            <h2 class="font-semibold">Absensi Hari Ini</h2>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">Nama</th>
                        <th class="px-4 py-2 font-medium">Shift</th>
                        <th class="px-4 py-2 font-medium">Jadwal</th>
                        <th class="px-4 py-2 font-medium">Masuk</th>
                        <th class="px-4 py-2 font-medium">Pulang</th>
                        <th class="px-4 py-2 font-medium">Telat</th>
                        <th class="px-4 py-2 font-medium">Plg Cepat</th>
                        <th class="px-4 py-2 font-medium">Lembur</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rekap as $a)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2 font-medium">{{ $a->employee?->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-slate-500">{{ $a->shift?->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-slate-500">{{ $a->scheduled_in?->format('H:i') ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $a->check_in_at?->format('H:i') ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $a->check_out_at?->format('H:i') ?? '—' }}</td>
                            <td class="px-4 py-2 {{ $a->late_minutes > 0 ? 'font-medium text-amber-700' : 'text-slate-400' }}">
                                {{ $a->late_minutes > 0 ? $a->late_minutes.' m' : '—' }}
                            </td>
                            <td class="px-4 py-2 {{ $a->early_leave_minutes > 0 ? 'font-medium text-orange-700' : 'text-slate-400' }}">
                                {{ $a->early_leave_minutes > 0 ? $a->early_leave_minutes.' m' : '—' }}
                            </td>
                            <td class="px-4 py-2 {{ $a->overtime_minutes > 0 ? 'font-medium text-indigo-700' : 'text-slate-400' }}">
                                {{ $a->overtime_minutes > 0 ? round($a->overtime_minutes / 60, 1).' j' : '—' }}
                            </td>
                            <td class="px-4 py-2">
                                <x-status-badge :warna="$a->status->color()" :label="$a->status->label()" />
                                @if ($a->has_adjustment)
                                    <span class="ml-1 text-xs text-slate-400" title="{{ $a->source_note }}">dikoreksi</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-8 text-center text-slate-400">Belum ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Scan mentah. Ditampilkan apa adanya, termasuk yang PIN-nya tak dikenal,
         karena inilah bukti pertama saat ada sengketa jam. --}}
    <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-4 py-3">
            <h2 class="font-semibold">Aktivitas Scan</h2>
            <p class="text-xs text-slate-500">
                Termasuk scan pulang shift malam yang jatuh setelah tengah malam.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">Waktu</th>
                        <th class="px-4 py-2 font-medium">PIN</th>
                        <th class="px-4 py-2 font-medium">Nama</th>
                        <th class="px-4 py-2 font-medium">Sumber</th>
                        <th class="px-4 py-2 font-medium">Foto</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($scan as $log)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2 whitespace-nowrap">
                                {{ $log->scanned_at->format('d M H:i:s') }}
                                @if (! $log->scanned_at->isSameDay($tanggal))
                                    <span class="text-xs text-slate-400">(+1 hari)</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $log->pin }}</td>
                            <td class="px-4 py-2">
                                {{ $log->employee?->name ?? '—' }}
                                @unless ($log->employee)
                                    <span class="text-xs text-amber-600">belum terdaftar</span>
                                @endunless
                            </td>
                            <td class="px-4 py-2 text-slate-500">{{ $log->source }}</td>
                            <td class="px-4 py-2">
                                @if ($log->photo_url)
                                    <a href="{{ $log->photo_url }}" target="_blank" rel="noopener"
                                       class="text-slate-700 underline">lihat</a>
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">Belum ada scan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

@endsection
