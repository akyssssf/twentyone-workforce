@extends('layouts.app')
@section('title', 'Beranda')

@section('content')

<div class="mb-5 flex items-center gap-3.5 sm:mb-6">
    <x-avatar :employee="$employee" ukuran="lg" :bisa-diklik="true" />
    <div class="min-w-0">
        <h1 class="truncate text-xl font-semibold tracking-tight sm:text-2xl">Halo, {{ $employee->name }}</h1>
        <p class="mt-0.5 text-sm text-slate-500">
            {{ $employee->primaryDivision()?->name }} &middot; {{ now()->translatedFormat('l, d F Y') }}
        </p>
    </div>
</div>

{{-- Menunggu jawaban SAYA. Ditaruh paling atas karena selama belum dijawab,
     pengajuan rekan tertahan dan dia tidak bisa berbuat apa-apa. --}}
@if ($menungguJawaban->isNotEmpty())
    <div class="kartu mb-5 border-amber-300 bg-amber-50">
        <div class="kartu-judul border-amber-200">
            <h2 class="text-sm font-semibold text-amber-900">Menunggu jawaban Anda</h2>
            <x-status-badge warna="amber" :label="$menungguJawaban->count() . ' permintaan'" />
        </div>
        <div class="divide-y divide-amber-200">
            @foreach ($menungguJawaban as $p)
                <a href="{{ route('karyawan.pengajuan.show', $p) }}"
                   class="flex items-center gap-3 px-4 py-3 transition hover:bg-amber-100/60 sm:px-5">
                    <x-avatar :employee="$p->employee" ukuran="sm" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-amber-900">
                            {{ $p->employee?->name }} menunjuk Anda jadi pengganti
                        </p>
                        <p class="truncate text-xs text-amber-800">
                            {{ $p->type->label() }} &middot; {{ $p->code }}
                        </p>
                    </div>
                    <span class="btn btn-kecil bg-amber-600 text-white">Jawab</span>
                </a>
            @endforeach
        </div>
    </div>
@endif

{{-- Pengingat lembur. Isinya sengaja tidak memuat formulir kode — tempatnya
     di halaman Lembur, supaya cuma ada satu tempat memasukkan kode dan tidak
     ada yang bingung mana yang berlaku. --}}
@if ($lemburBelumAktif->isNotEmpty())
    <a href="{{ route('karyawan.lembur.index') }}"
       class="kartu mb-5 flex items-center gap-3 border-indigo-300 bg-indigo-50 px-4 py-3.5 transition hover:bg-indigo-100 sm:px-5">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path d="M12 8v4l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-indigo-900">Anda ditunjuk lembur</p>
            <p class="truncate text-xs text-indigo-800">
                {{ $lemburBelumAktif->pluck('work_date')->map(fn ($d) => $d->translatedFormat('d M'))->implode(', ') }}
                &middot; masukkan kode untuk mengaktifkan
            </p>
        </div>
        <span class="btn btn-kecil bg-indigo-600 text-white">Masukkan kode</span>
    </a>
@endif

<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">

        {{-- Hari ini --}}
        <div class="kartu">
            <div class="kartu-judul"><h2 class="font-semibold">Hari Ini</h2></div>
            <div class="kartu-isi">
                @forelse ($jadwalHariIni as $j)
                    <div class="mb-3 flex flex-wrap items-center gap-2.5 last:mb-0">
                        @if ($j->shift)
                            <span class="rounded-lg px-2.5 py-1 text-sm font-medium text-white"
                                  style="background: {{ $j->shift->color ?? '#475569' }}">
                                {{ $j->shift->name }}
                            </span>
                            <span class="text-sm text-slate-600">
                                {{ substr($j->shift->start_time, 0, 5) }} – {{ substr($j->shift->end_time, 0, 5) }}
                            </span>
                            <span class="text-sm text-slate-400">sebagai {{ $j->division?->name }}</span>
                        @else
                            <x-status-badge warna="slate" :label="$j->status->label()" />
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-slate-400">Tidak ada jadwal hari ini.</p>
                @endforelse

                @foreach ($absensiHariIni as $a)
                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 rounded-xl bg-slate-50 px-3.5 py-2.5 text-sm">
                        <span>Masuk <strong class="tabular-nums">{{ $a->check_in_at?->format('H:i') ?? '—' }}</strong></span>
                        <span>Pulang <strong class="tabular-nums">{{ $a->check_out_at?->format('H:i') ?? '—' }}</strong></span>
                        @if ($a->late_minutes > 0)
                            <span class="text-amber-700">Telat {{ $a->late_minutes }} menit</span>
                        @endif
                        @if ($a->overtime_minutes > 0)
                            <span class="text-indigo-700">Lembur {{ round($a->overtime_minutes / 60, 1) }} jam</span>
                        @endif
                        <x-status-badge :warna="$a->status->color()" :label="$a->status->label()" />
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Siapa saja yang bertugas hari ini.

             Bukan sekadar informasi: inilah yang dilihat karyawan saat mau
             menunjuk pengganti, dan saat ingin tahu hari ini sedapur dengan
             siapa. --}}
        <div class="kartu">
            <div class="kartu-judul">
                <h2 class="font-semibold">Roster Hari Ini</h2>
                <span class="text-xs text-slate-500">{{ now()->translatedFormat('d M Y') }}</span>
            </div>

            @forelse ($shifts as $shift)
                @php $petugas = $rosterHariIni->get($shift->id, collect()); @endphp
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

        {{-- Jadwal mendatang --}}
        <div class="kartu">
            <div class="kartu-judul">
                <h2 class="font-semibold">Jadwal Berikutnya</h2>
                <a href="{{ route('karyawan.jadwal') }}" class="text-sm text-slate-500 transition hover:text-slate-900">Sebulan</a>
            </div>
            <div class="divide-y divide-slate-50">
                @forelse ($jadwalMendatang as $j)
                    <div class="flex items-center justify-between px-4 py-2.5 text-sm sm:px-5">
                        <span>{{ $j->work_date->translatedFormat('l, d M') }}</span>
                        @if ($j->shift)
                            <span class="rounded-md px-2 py-0.5 text-xs font-medium text-white"
                                  style="background: {{ $j->shift->color ?? '#475569' }}">
                                {{ $j->shift->name }}
                            </span>
                        @else
                            <span class="text-xs text-slate-400">{{ $j->status->label() }}</span>
                        @endif
                    </div>
                @empty
                    <x-kosong pesan="Roster bulan ini belum diterbitkan." />
                @endforelse
            </div>
        </div>
    </div>

    <div class="space-y-5">

        {{-- Aksi cepat: alasan utama karyawan membuka aplikasi ini. --}}
        <div class="kartu">
            <div class="kartu-judul"><h2 class="font-semibold">Ajukan</h2></div>
            <div class="kartu-isi">
                <div class="grid grid-cols-3 gap-2">
                    @foreach ([
                        'leave' => 'Cuti / Izin',
                        'swap' => 'Tukar Shift',
                        'correction' => 'Koreksi',
                    ] as $type => $label)
                        <a href="{{ route('karyawan.pengajuan.create', $type) }}"
                           class="btn-netral min-h-16 flex-col gap-0.5 px-1 text-center text-xs leading-tight">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>

                <p class="mt-3 text-xs leading-relaxed text-slate-400">
                    Setiap pengajuan wajib menunjuk pengganti.
                    <span class="mt-1 block">
                        <strong class="text-slate-500">Lembur tidak diajukan sendiri</strong> — admin yang menunjuk,
                        lalu Anda menerima kode untuk mengaktifkannya.
                    </span>
                </p>

                <x-tombol-wa class="mt-3 w-full"
                             label="Konfirmasi ke Admin"
                             :pesan="'Halo Admin, saya ' . $employee->name . ' mau konfirmasi pengajuan saya.'" />
            </div>
        </div>

        {{-- Saldo cuti --}}
        <div class="kartu">
            <div class="kartu-judul"><h2 class="font-semibold">Saldo Cuti</h2></div>
            <div class="divide-y divide-slate-50">
                @forelse ($saldoCuti as $saldo)
                    <div class="flex items-center justify-between px-4 py-2.5 text-sm sm:px-5">
                        <span>{{ $saldo->leaveType?->name }}</span>
                        <span class="font-semibold tabular-nums">
                            {{ rtrim(rtrim(number_format($saldo->remaining(), 1), '0'), '.,') }} hari
                        </span>
                    </div>
                @empty
                    <x-kosong pesan="Belum ada saldo tercatat." />
                @endforelse
            </div>
        </div>

        {{-- Pengajuan berjalan --}}
        <div class="kartu">
            <div class="kartu-judul"><h2 class="font-semibold">Pengajuan Berjalan</h2></div>
            <div class="divide-y divide-slate-50">
                @forelse ($pengajuanTerbuka as $p)
                    <a href="{{ route('karyawan.pengajuan.show', $p) }}"
                       class="flex items-center justify-between gap-2 px-4 py-2.5 text-sm transition hover:bg-slate-50 sm:px-5">
                        <span class="truncate">{{ $p->type->shortLabel() }}</span>
                        <x-status-badge :warna="$p->status->color()" :label="$p->status->label()" />
                    </a>
                @empty
                    <x-kosong pesan="Tidak ada." />
                @endforelse
            </div>
        </div>

        @if ($slipTerbaru)
            <a href="{{ route('karyawan.slip.show', $slipTerbaru) }}" class="kartu block p-4 transition hover:bg-slate-50">
                <div class="text-xs text-slate-500">Slip gaji terbaru</div>
                <div class="mt-1 text-lg font-semibold tabular-nums">
                    Rp {{ number_format($slipTerbaru->take_home_pay, 0, ',', '.') }}
                </div>
                <div class="text-xs text-slate-400">{{ $slipTerbaru->run->period->label() }}</div>
            </a>
        @endif
    </div>
</div>

@endsection
