@extends('layouts.app')
@section('title', 'Lembur')
@section('lebar', 'max-w-3xl')

@section('content')

{{-- Kotak kode ditaruh paling atas dan dibuat besar.

     Ini satu-satunya tindakan yang membuat lembur terhitung, dan dilakukan
     sambil berdiri di dapur menjelang shift. Kalau kotaknya kecil atau harus
     digulir dulu, orang akan menyerah dan bekerja tanpa dibayar. --}}
<div class="kartu mb-5 border-indigo-300 bg-indigo-50">
    <div class="kartu-isi text-center">
        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-600 text-white">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path d="M12 8v4l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </div>

        <h2 class="text-lg font-semibold text-indigo-900">Mulai Lembur</h2>
        <p class="mx-auto mt-1 max-w-md text-sm text-indigo-800">
            Masukkan kode yang diberikan admin. Lembur baru terhitung setelah kodenya diaktifkan —
            tanpa itu, waktu setelah jam pulang tidak dibayar walaupun Anda tetap scan.
        </p>

        <form method="POST" action="{{ route('karyawan.lembur.aktivasi') }}" class="mx-auto mt-4 max-w-sm space-y-2">
            @csrf
            <input type="text" name="kode" required maxlength="12" placeholder="K7M2QD"
                   autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false"
                   class="kolom py-3 text-center font-mono text-2xl font-bold uppercase tracking-[0.35em]">
            <button class="btn w-full bg-indigo-600 py-3 text-white hover:bg-indigo-700">
                Aktifkan Lembur
            </button>
        </form>
    </div>
</div>

{{-- Penugasan yang menunggu kode. --}}
@if ($belumAktif->isNotEmpty())
    <div class="kartu mb-5">
        <div class="kartu-judul">
            <h2 class="font-semibold">Menunggu Diaktifkan</h2>
            <x-status-badge warna="amber" :label="$belumAktif->count()" />
        </div>
        <div class="divide-y divide-slate-50">
            @foreach ($belumAktif as $r)
                <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 sm:px-5">
                    <div>
                        <p class="text-sm font-medium">{{ $r->work_date->translatedFormat('l, d M Y') }}</p>
                        <p class="text-xs text-slate-500">
                            Rencana {{ $r->approved_minutes }} menit
                            @if ($r->overtimeRequest)
                                &middot; {{ substr($r->overtimeRequest->planned_start, 0, 5) }}–{{ substr($r->overtimeRequest->planned_end, 0, 5) }}
                            @endif
                        </p>
                    </div>
                    <x-status-badge warna="amber" label="Belum diaktifkan" />
                </div>
            @endforeach
        </div>
    </div>
@endif

{{-- Riwayat --}}
<div class="kartu overflow-hidden">
    <div class="kartu-judul">
        <div>
            <h2 class="font-semibold">Riwayat Lembur</h2>
            <p class="text-xs text-slate-500">Yang dibayar adalah menit yang disahkan admin setelah Anda bekerja.</p>
        </div>
    </div>

    <div class="tabel-bungkus">
        <table class="tabel">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th class="text-right">Rencana</th>
                    <th class="text-right">Aktual</th>
                    <th class="text-right">Dibayar</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($riwayat as $r)
                    <tr>
                        <td class="whitespace-nowrap">{{ $r->work_date->translatedFormat('d M Y') }}</td>
                        <td class="text-right tabular-nums text-slate-500">{{ $r->approved_minutes }} m</td>
                        <td class="text-right tabular-nums text-slate-500">{{ $r->actual_minutes ?: '—' }}</td>
                        <td class="text-right font-medium tabular-nums">
                            {{ $r->payable_minutes > 0 ? $r->payable_minutes . ' m' : '—' }}
                        </td>
                        <td>
                            @if ($r->status === 'confirmed')
                                <x-status-badge warna="emerald" label="Disahkan" />
                            @elseif ($r->status === 'rejected')
                                <x-status-badge warna="red" label="Ditolak" />
                            @elseif ($r->isActivated())
                                <x-status-badge warna="sky" label="Aktif, menunggu pengesahan" />
                            @else
                                <x-status-badge warna="amber" label="Belum diaktifkan" />
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <x-kosong pesan="Belum pernah ditugaskan lembur." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<p class="mt-4 text-center text-xs leading-relaxed text-slate-400">
    Lembur tidak bisa diajukan sendiri. Admin yang menunjuk, lalu Anda menerima kodenya.
</p>

@endsection
