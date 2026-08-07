@extends('layouts.app')
@section('title', 'Lembur')

@section('content')

<x-judul-halaman keterangan="Lembur tidak pernah terjadi otomatis. Admin menunjuk orangnya, orang itu mengaktifkan dengan kode, lalu realisasinya disahkan." />

{{-- 1. Tugaskan --}}
<div class="kartu mb-5">
    <div class="kartu-judul"><h2 class="font-semibold">Tugaskan Lembur</h2></div>

    <form method="POST" action="{{ route('manajer.lembur.store') }}" class="kartu-isi space-y-4">
        @csrf

        <div>
            <label class="label">Siapa yang lembur</label>
            <select name="employee_ids[]" multiple size="6" required class="kolom mt-1">
                @foreach ($employees as $e)
                    <option value="{{ $e->id }}">{{ $e->name }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">Tahan Ctrl/Cmd untuk memilih beberapa orang sekaligus.</p>
        </div>

        <div>
            <label class="label">Pengganti mereka</label>
            <select name="substitute_employee_id" required class="kolom mt-1">
                <option value="">— pilih rekan —</option>
                @foreach ($employees as $e)
                    <option value="{{ $e->id }}">{{ $e->name }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">
                Orang yang menutup posisi mereka. Wajib, sama seperti pengajuan lain.
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="label">Tanggal</label>
                <input type="date" name="work_date" required class="kolom mt-1">
            </div>
            <div>
                <label class="label">Mulai</label>
                <input type="time" name="planned_start" required class="kolom mt-1">
            </div>
            <div>
                <label class="label">Selesai</label>
                <input type="time" name="planned_end" required class="kolom mt-1">
            </div>
        </div>

        <div>
            <label class="label">Alasan</label>
            <input type="text" name="reason" required minlength="5" class="kolom mt-1">
        </div>

        <button class="btn-utama w-full sm:w-auto">Tugaskan &amp; setujui</button>
    </form>
</div>

{{-- 2. Kode yang harus dibagikan.

     Ditaruh sebelum daftar pengesahan karena inilah tindakan admin berikutnya
     setelah menugaskan: membacakan kode ke orangnya. Lembur yang kodenya tidak
     pernah sampai adalah lembur yang tidak akan pernah terhitung. --}}
@if ($belumAktif->isNotEmpty())
    <div class="kartu mb-5 border-amber-300">
        <div class="kartu-judul border-amber-200">
            <div>
                <h2 class="font-semibold">Kode Belum Dipakai</h2>
                <p class="text-xs text-slate-500">Bacakan ke orangnya. Tanpa diaktifkan, lemburnya tidak dibayar.</p>
            </div>
            <x-status-badge warna="amber" :label="$belumAktif->count()" />
        </div>

        <div class="divide-y divide-slate-50">
            @foreach ($belumAktif as $r)
                <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-5">
                    <div class="flex min-w-0 items-center gap-3">
                        <x-avatar :employee="$r->employee" ukuran="sm" />
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">{{ $r->employee?->name }}</p>
                            <p class="text-xs text-slate-500">
                                {{ $r->work_date->translatedFormat('d M Y') }} &middot; {{ $r->approved_minutes }} menit
                            </p>
                        </div>
                    </div>

                    <span class="rounded-lg bg-indigo-50 px-3 py-1.5 font-mono text-base font-bold tracking-[0.2em] text-indigo-900">
                        {{ $r->overtimeRequest?->secret_code ?? '—' }}
                    </span>
                </div>
            @endforeach
        </div>
    </div>
@endif

{{-- 3. Sahkan realisasi --}}
<div class="kartu mb-5">
    <div class="kartu-judul">
        <div>
            <h2 class="font-semibold">Menunggu Pengesahan</h2>
            <p class="text-xs text-slate-500">Yang dibayar min(disetujui, aktual). Menaikkan di atas itu wajib pakai catatan.</p>
        </div>
    </div>

    <div class="divide-y divide-slate-50">
        @forelse ($records as $r)
            <div class="px-4 py-3 sm:px-5">
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <x-avatar :employee="$r->employee" ukuran="sm" />
                    <span class="text-sm font-medium">{{ $r->employee?->name }}</span>
                    <span class="text-xs text-slate-500">
                        {{ $r->work_date->translatedFormat('d M Y') }} &middot; disetujui {{ $r->approved_minutes }} menit
                    </span>

                    @if ($r->isActivated())
                        <x-status-badge warna="emerald"
                                        :label="'Kode aktif ' . $r->activated_at->translatedFormat('d M H:i')" />
                    @else
                        <x-status-badge warna="amber" label="Kode belum dipakai" />
                    @endif
                </div>

                @php $saran = $r->saranMenit(); @endphp

                @if ($saran !== null)
                    <p class="mb-2 text-xs text-slate-500">
                        Saran dari jam scan pulang asli: <strong class="text-slate-700">{{ $saran }} menit</strong>
                        lewat jadwal pulang. Sudah keisi otomatis di bawah, tinggal cek lalu sahkan (atau ubah manual
                        kalau tidak sesuai).
                    </p>
                @else
                    <p class="mb-2 text-xs text-amber-700">
                        Belum ada scan pulang tercatat untuk tanggal ini — isi manual berdasarkan laporan orangnya.
                    </p>
                @endif

                <form method="POST" action="{{ route('manajer.lembur.confirm', $r) }}"
                      class="flex flex-wrap items-end gap-2">
                    @csrf
                    <div>
                        <label class="label">Aktual (menit)</label>
                        <input type="number" name="actual_minutes" min="0"
                               value="{{ $saran ?? $r->approved_minutes }}" required
                               class="kolom mt-0.5 w-24">
                    </div>
                    <div>
                        <label class="label">Dibayar (menit)</label>
                        <input type="number" name="payable_minutes" min="0"
                               value="{{ min($saran ?? $r->approved_minutes, $r->approved_minutes) }}" required
                               class="kolom mt-0.5 w-24">
                    </div>
                    <input type="text" name="note" placeholder="Catatan" class="kolom min-w-40 flex-1">
                    <button class="btn-setuju">Sahkan</button>
                </form>
            </div>
        @empty
            <x-kosong pesan="Tidak ada realisasi yang menunggu." />
        @endforelse
    </div>
</div>

{{-- 4. Riwayat --}}
<div class="kartu overflow-hidden">
    <div class="kartu-judul"><h2 class="font-semibold">Lembur Disahkan</h2></div>

    <div class="tabel-bungkus">
        <table class="tabel">
            <thead>
                <tr>
                    <th>Karyawan</th>
                    <th>Tanggal</th>
                    <th class="text-right">Disetujui</th>
                    <th class="text-right">Aktual</th>
                    <th class="text-right">Dibayar</th>
                    <th>Kode</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($confirmed as $r)
                    <tr>
                        <td>
                            <div class="flex items-center gap-2.5">
                                <x-avatar :employee="$r->employee" ukuran="sm" />
                                <span class="whitespace-nowrap font-medium">{{ $r->employee?->name }}</span>
                            </div>
                        </td>
                        <td class="whitespace-nowrap text-slate-500">{{ $r->work_date->translatedFormat('d M Y') }}</td>
                        <td class="text-right tabular-nums text-slate-500">{{ $r->approved_minutes }} m</td>
                        <td class="text-right tabular-nums text-slate-500">{{ $r->actual_minutes }} m</td>
                        <td class="text-right font-medium tabular-nums">{{ $r->payable_minutes }} m</td>
                        <td>
                            @if ($r->isActivated())
                                <x-status-badge warna="emerald" label="Dipakai" />
                            @else
                                <x-status-badge warna="slate" label="Tanpa kode" />
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-kosong pesan="Belum ada." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
