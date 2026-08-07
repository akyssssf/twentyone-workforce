@extends('layouts.app')

@section('title', 'Rekap Absensi')
@section('lebar', 'max-w-6xl')

@section('content')

    <div class="mb-5">
        <h1 class="text-xl font-semibold tracking-tight sm:text-2xl">Rekap Absensi</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $report->judulPeriode() }}</p>
    </div>

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        {{-- Ganti granularitas --}}
        <div class="inline-flex rounded-lg border border-slate-300 bg-white p-1 text-sm">
            @foreach (['harian' => 'Harian', 'mingguan' => 'Mingguan', 'bulanan' => 'Bulanan'] as $kunci => $label)
                <a href="{{ route('laporan', ['tampilan' => $kunci]) }}"
                   class="rounded-md px-3 py-1.5 font-medium transition {{ $tampilan === $kunci ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <div class="flex flex-wrap items-end gap-2">
            @if ($tampilan === 'harian')
                <form method="GET" class="flex items-end gap-2">
                    <input type="hidden" name="tampilan" value="harian">
                    <div>
                        <label for="tanggal" class="label">Tanggal</label>
                        <input id="tanggal" type="date" name="tanggal" value="{{ $periode->format('Y-m-d') }}"
                               class="mt-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
                    </div>
                    <button type="submit" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Lihat</button>
                </form>
            @elseif ($tampilan === 'mingguan')
                <form method="GET" class="flex items-end gap-2">
                    <input type="hidden" name="tampilan" value="mingguan">
                    <div>
                        <label for="minggu" class="label">Minggu</label>
                        <input id="minggu" type="week" name="minggu" value="{{ $periode->format('o-\WW') }}"
                               class="mt-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
                    </div>
                    <button type="submit" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Lihat</button>
                </form>
            @else
                <form method="GET" class="flex items-end gap-2">
                    <input type="hidden" name="tampilan" value="bulanan">
                    <div>
                        <label for="bulan" class="label">Bulan</label>
                        <input id="bulan" type="month" name="bulan" value="{{ $periode->format('Y-m') }}"
                               class="mt-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
                    </div>
                    <button type="submit" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Lihat</button>
                </form>
            @endif

            <textarea id="salin-wa-sumber" class="hidden" aria-hidden="true">{{ $waTeks }}</textarea>
            <button type="button" id="salin-wa"
                    class="inline-flex items-center gap-2 rounded-lg border border-emerald-600 px-4 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-50">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                </svg>
                <span id="salin-wa-label">Salin buat WA</span>
            </button>

            <a href="{{ route('laporan.excel', request()->query()) }}"
               class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/>
                </svg>
                Unduh Excel
            </a>
        </div>
    </div>

    <script>
        document.getElementById('salin-wa')?.addEventListener('click', async function () {
            const teks = document.getElementById('salin-wa-sumber').value;
            const label = document.getElementById('salin-wa-label');

            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(teks);
                } else {
                    // Fallback untuk konteks non-HTTPS (mis. dev lokal via http://).
                    const area = document.createElement('textarea');
                    area.value = teks;
                    area.style.position = 'fixed';
                    area.style.opacity = '0';
                    document.body.appendChild(area);
                    area.focus();
                    area.select();
                    document.execCommand('copy');
                    document.body.removeChild(area);
                }

                const asli = label.textContent;
                label.textContent = 'Tersalin!';
                setTimeout(() => { label.textContent = asli; }, 2000);
            } catch (e) {
                alert('Gagal menyalin. Salin manual dari sini:\n\n' + teks);
            }
        });
    </script>

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
            <div class="kartu p-4">
                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $item['label'] }}</div>
                <div class="mt-1 text-2xl font-semibold {{ $item['warna'] }}">{{ $item['nilai'] }}</div>
            </div>
        @endforeach

        <div class="kartu p-4">
            <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Total telat</div>
            <div class="mt-1 text-xl font-semibold text-amber-700">{{ $total['total_telat_menit'] }} menit</div>
        </div>

        <div class="kartu p-4">
            <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Total lembur</div>
            <div class="mt-1 text-xl font-semibold text-indigo-700">
                {{ round($total['total_lembur_menit'] / 60, 1) }} jam
            </div>
        </div>
    </div>

    @if ($total['alpha'] > 0)
        <div class="pemberitahuan mb-5 border-amber-200 bg-amber-50 text-amber-900">
            Ada {{ $total['alpha'] }} hari terhitung <strong>alpha</strong>. Kalau sebagiannya sebenarnya hari
            libur, atur libur mingguan lewat <code class="rounded bg-amber-100 px-1">employee:edit PIN --off-days</code>
            atau libur bersama lewat <code class="rounded bg-amber-100 px-1">holiday add</code>, lalu hitung ulang.
        </div>
    @endif

    {{-- Tabel rekap --}}
    <div class="overflow-hidden kartu">
        <div class="kartu-judul">
            <h2 class="text-sm font-semibold">Per karyawan</h2>
        </div>

        @if ($ringkasan->isEmpty())
            <p class="px-4 py-8 text-center text-sm text-slate-500">
                Belum ada karyawan aktif. Tambahkan dengan
                <code class="rounded bg-slate-100 px-1">php artisan employee:add</code>.
            </p>
        @else
            <div class="tabel-bungkus">
                <table class="tabel">
                    <thead>
                        <tr>
                            <th >PIN</th>
                            <th >Karyawan</th>
                            <th >Shift</th>
                            <th class="text-center">Hadir</th>
                            <th class="text-center">Telat</th>
                            <th class="text-center">Alpha</th>
                            <th class="text-center">Libur</th>
                            <th >Total telat</th>
                            <th class="text-center">Plg cepat</th>
                            <th >Lembur</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ringkasan as $baris)
                            <tr>
                                <td class="font-mono text-xs text-slate-500">{{ $baris['pin'] }}</td>
                                <td >{{ $baris['nama'] }}</td>
                                <td class="text-slate-600">{{ $baris['shift'] }}</td>
                                <td class="text-center">{{ $baris['hadir'] ?: '-' }}</td>
                                <td class="text-center">
                                    @if ($baris['telat'] > 0)
                                        <span class="text-amber-700">{{ $baris['telat'] }}</span>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($baris['alpha'] > 0)
                                        <span class="text-red-600">{{ $baris['alpha'] }}</span>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="text-center text-slate-500">{{ $baris['libur'] ?: '-' }}</td>
                                <td class="text-slate-600">
                                    {{ \App\Services\Attendance\MonthlyReport::durasi($baris['total_telat_detik']) }}
                                </td>
                                <td class="text-center">
                                    @if ($baris['pulang_cepat'] > 0)
                                        <span class="text-orange-700">{{ $baris['pulang_cepat'] }}</span>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="text-slate-600">
                                    {{ $baris['total_lembur_menit'] > 0 ? round($baris['total_lembur_menit'] / 60, 1).' jam' : '-' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50 text-sm font-semibold">
                        <tr>
                            <td  colspan="3">TOTAL</td>
                            <td class="text-center">{{ $total['hadir'] }}</td>
                            <td class="text-center">{{ $total['telat'] }}</td>
                            <td class="text-center">{{ $total['alpha'] }}</td>
                            <td class="text-center">{{ $total['libur'] }}</td>
                            <td >{{ $total['total_telat_menit'] }} m</td>
                            <td class="text-center">{{ $total['pulang_cepat'] }}</td>
                            <td >{{ round($total['total_lembur_menit'] / 60, 1) }} jam</td>
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
