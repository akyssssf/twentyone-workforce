@extends('layouts.app')

@section('title', 'Rekap Bulanan')

@section('content')

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Rekap Bulanan</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $report->judulPeriode() }}</p>
        </div>

        <div class="flex items-end gap-2">
            <form method="GET" class="flex items-end gap-2">
                <div>
                    <label for="bulan" class="block text-xs font-medium text-slate-500">Bulan</label>
                    <input id="bulan" type="month" name="bulan" value="{{ $periode->format('Y-m') }}"
                           class="mt-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
                </div>
                <button type="submit"
                        class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                    Lihat
                </button>
            </form>

            <a href="{{ route('laporan.excel', ['bulan' => $periode->format('Y-m')]) }}"
               class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/>
                </svg>
                Unduh Excel
            </a>
        </div>
    </div>

    {{-- Ringkasan sebulan --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-7">
        @php
            $kartu = [
                ['label' => 'Karyawan', 'nilai' => $total['karyawan'], 'warna' => 'text-slate-900'],
                ['label' => 'Hadir', 'nilai' => $total['hadir'], 'warna' => 'text-emerald-600'],
                ['label' => 'Telat', 'nilai' => $total['telat'], 'warna' => 'text-amber-600'],
                ['label' => 'Alpha', 'nilai' => $total['alpha'], 'warna' => 'text-red-600'],
                ['label' => 'Libur', 'nilai' => $total['libur'], 'warna' => 'text-slate-500'],
            ];
        @endphp

        @foreach ($kartu as $item)
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $item['label'] }}</div>
                <div class="mt-1 text-2xl font-semibold {{ $item['warna'] }}">{{ $item['nilai'] }}</div>
            </div>
        @endforeach

        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Total telat</div>
            <div class="mt-1 text-xl font-semibold text-amber-700">{{ $total['total_telat_menit'] }} menit</div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Total lembur</div>
            <div class="mt-1 text-xl font-semibold text-indigo-700">
                {{ round($total['total_lembur_menit'] / 60, 1) }} jam
            </div>
        </div>
    </div>

    @if ($total['alpha'] > 0)
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Ada {{ $total['alpha'] }} hari terhitung <strong>alpha</strong>. Kalau sebagiannya sebenarnya hari
            libur, atur libur mingguan lewat <code class="rounded bg-amber-100 px-1">employee:edit PIN --off-days</code>
            atau libur bersama lewat <code class="rounded bg-amber-100 px-1">holiday add</code>, lalu hitung ulang.
        </div>
    @endif

    {{-- Tabel rekap --}}
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold">Per karyawan</h2>
        </div>

        @if ($ringkasan->isEmpty())
            <p class="px-4 py-8 text-center text-sm text-slate-500">
                Belum ada karyawan aktif. Tambahkan dengan
                <code class="rounded bg-slate-100 px-1">php artisan employee:add</code>.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">PIN</th>
                            <th class="px-4 py-2 font-medium">Karyawan</th>
                            <th class="px-4 py-2 font-medium">Shift</th>
                            <th class="px-4 py-2 text-center font-medium">Hadir</th>
                            <th class="px-4 py-2 text-center font-medium">Telat</th>
                            <th class="px-4 py-2 text-center font-medium">Alpha</th>
                            <th class="px-4 py-2 text-center font-medium">Libur</th>
                            <th class="px-4 py-2 font-medium">Total telat</th>
                            <th class="px-4 py-2 text-center font-medium">Plg cepat</th>
                            <th class="px-4 py-2 font-medium">Lembur</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($ringkasan as $baris)
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs text-slate-500">{{ $baris['pin'] }}</td>
                                <td class="px-4 py-2 font-medium">{{ $baris['nama'] }}</td>
                                <td class="px-4 py-2 text-slate-600">{{ $baris['shift'] }}</td>
                                <td class="px-4 py-2 text-center">{{ $baris['hadir'] ?: '-' }}</td>
                                <td class="px-4 py-2 text-center">
                                    @if ($baris['telat'] > 0)
                                        <span class="text-amber-700">{{ $baris['telat'] }}</span>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-center">
                                    @if ($baris['alpha'] > 0)
                                        <span class="text-red-600">{{ $baris['alpha'] }}</span>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-center text-slate-500">{{ $baris['libur'] ?: '-' }}</td>
                                <td class="px-4 py-2 text-slate-600">
                                    {{ \App\Services\Attendance\MonthlyReport::durasi($baris['total_telat_detik']) }}
                                </td>
                                <td class="px-4 py-2 text-center">
                                    @if ($baris['pulang_cepat'] > 0)
                                        <span class="text-orange-700">{{ $baris['pulang_cepat'] }}</span>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-slate-600">
                                    {{ $baris['total_lembur_menit'] > 0 ? round($baris['total_lembur_menit'] / 60, 1).' jam' : '-' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50 text-sm font-semibold">
                        <tr>
                            <td class="px-4 py-2" colspan="3">TOTAL</td>
                            <td class="px-4 py-2 text-center">{{ $total['hadir'] }}</td>
                            <td class="px-4 py-2 text-center">{{ $total['telat'] }}</td>
                            <td class="px-4 py-2 text-center">{{ $total['alpha'] }}</td>
                            <td class="px-4 py-2 text-center">{{ $total['libur'] }}</td>
                            <td class="px-4 py-2">{{ $total['total_telat_menit'] }} m</td>
                            <td class="px-4 py-2 text-center">{{ $total['pulang_cepat'] }}</td>
                            <td class="px-4 py-2">{{ round($total['total_lembur_menit'] / 60, 1) }} jam</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>

    <p class="mt-4 text-xs text-slate-400">
        Berkas Excel berisi dua sheet: <strong>Ringkasan</strong> seperti tabel di atas, dan
        <strong>Rincian Harian</strong> yang memuat setiap tanggal per karyawan.
    </p>

@endsection
