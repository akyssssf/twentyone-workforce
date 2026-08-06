@extends('layouts.app')

@section('title', 'Aktivitas Scan')

@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight">Aktivitas Scan</h1>
        <p class="mt-1 text-sm text-slate-500">Semua scan yang masuk ke sistem, terbaru di atas</p>
    </div>

    {{-- Ringkasan --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Total scan</div>
            <div class="mt-1 text-2xl font-semibold">{{ number_format($ringkasan['total'], 0, ',', '.') }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Dari webhook</div>
            <div class="mt-1 text-2xl font-semibold text-sky-600">{{ $ringkasan['webhook'] }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Dari sync</div>
            <div class="mt-1 text-2xl font-semibold text-violet-600">{{ $ringkasan['sync'] }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Scan terakhir</div>
            <div class="mt-1 text-sm font-semibold">
                @if ($ringkasan['terakhir'])
                    {{ \Illuminate\Support\Carbon::parse($ringkasan['terakhir'])->format('d M H:i:s') }}
                @else
                    <span class="text-slate-400">belum ada</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Kenapa isinya bisa berbeda dari dashboard mesin --}}
    <div class="mb-6 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
        Halaman ini hanya memuat scan yang <strong>berhasil</strong>. Percobaan yang gagal
        (<em>Authentication via Face Failed</em>) tidak dikirim Fingerspot lewat API mana pun,
        jadi kegagalan hanya terlihat di dashboard mesin. Kalau jumlah di sini lebih sedikit
        daripada di mesin, kemungkinan besar itu sebabnya, bukan data yang hilang.
    </div>

    @if ($ringkasan['asing']->isNotEmpty())
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <strong>PIN belum terdaftar:</strong> {{ $ringkasan['asing']->implode(', ') }}.
            Scan-nya tersimpan aman, tapi tidak masuk rekap sampai dibuatkan karyawan dengan
            <code class="rounded bg-amber-100 px-1">pin_device</code> yang sama.
        </div>
    @endif

    {{-- Penyaring --}}
    <form method="GET" class="mb-6 rounded-xl border border-slate-200 bg-white p-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <label for="dari" class="block text-xs font-medium text-slate-500">Dari tanggal</label>
                <input id="dari" type="date" name="dari" value="{{ $filter['dari'] }}"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
            </div>
            <div>
                <label for="sampai" class="block text-xs font-medium text-slate-500">Sampai tanggal</label>
                <input id="sampai" type="date" name="sampai" value="{{ $filter['sampai'] }}"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
            </div>
            <div>
                <label for="pin" class="block text-xs font-medium text-slate-500">PIN</label>
                <input id="pin" type="text" name="pin" value="{{ $filter['pin'] }}" placeholder="semua"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
            </div>
            <div>
                <label for="sumber" class="block text-xs font-medium text-slate-500">Sumber</label>
                <select id="sumber" name="sumber"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
                    <option value="">Semua</option>
                    <option value="webhook" @selected($filter['sumber'] === 'webhook')>Webhook</option>
                    <option value="sync" @selected($filter['sumber'] === 'sync')>Sync</option>
                </select>
            </div>
            <div class="flex items-end gap-2">
                <button type="submit"
                        class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                    Saring
                </button>
                <a href="{{ route('aktivitas') }}"
                   class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                    Reset
                </a>
            </div>
        </div>
    </form>

    {{-- Daftar scan --}}
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        @if ($scan->isEmpty())
            <p class="px-4 py-12 text-center text-sm text-slate-500">
                Tidak ada scan yang cocok.
                @if ($ringkasan['total'] === 0)
                    <br>
                    Tarik data dari mesin dengan
                    <code class="rounded bg-slate-100 px-1">php artisan attendance:sync</code>.
                @endif
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Foto</th>
                            <th class="px-4 py-2 font-medium">Waktu</th>
                            <th class="px-4 py-2 font-medium">PIN</th>
                            <th class="px-4 py-2 font-medium">Karyawan</th>
                            <th class="px-4 py-2 font-medium">Verifikasi</th>
                            <th class="px-4 py-2 font-medium">Jenis</th>
                            <th class="px-4 py-2 font-medium">Sumber</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($scan as $log)
                            <tr>
                                <td class="px-4 py-2">
                                    @if ($log->photo_url)
                                        <a href="{{ $log->photo_url }}" target="_blank" rel="noopener noreferrer"
                                           title="Buka foto ukuran penuh">
                                            <img src="{{ $log->photo_url }}" alt="Foto scan" loading="lazy"
                                                 class="h-11 w-11 rounded-lg object-cover ring-1 ring-slate-200 transition hover:ring-slate-400">
                                        </a>
                                    @else
                                        <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-slate-100 text-xs text-slate-400">
                                            &mdash;
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <div class="font-medium">{{ $log->scanned_at->format('H:i:s') }}</div>
                                    <div class="text-xs text-slate-500">{{ $log->scanned_at->translatedFormat('d M Y') }}</div>
                                </td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $log->pin }}</td>
                                <td class="px-4 py-2">
                                    @if ($namaPerPin->has($log->pin))
                                        {{ $namaPerPin[$log->pin] }}
                                    @else
                                        <span class="text-amber-700">belum terdaftar</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-slate-600">{{ $log->verifyModeLabel() ?? '-' }}</td>
                                <td class="px-4 py-2 text-slate-600">{{ $log->statusScanLabel() ?? '-' }}</td>
                                <td class="px-4 py-2">
                                    <span @class([
                                        'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                        'bg-sky-100 text-sky-800' => $log->source === 'webhook',
                                        'bg-violet-100 text-violet-800' => $log->source === 'sync',
                                    ])>{{ $log->source }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($scan->hasPages())
                <div class="border-t border-slate-200 px-4 py-3">
                    {{ $scan->links() }}
                </div>
            @endif
        @endif
    </div>

@endsection
