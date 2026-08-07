<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f172a">
    <title>@yield('title', 'Beranda') &middot; {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100 text-slate-900 antialiased">

@php
    $user = auth()->user();
    $isManajer = $user?->isManagement() ?? false;

    $ikon = [
        'hari' => 'M3 12h4l3 8 4-16 3 8h4',
        'roster' => 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z',
        'pengajuan' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.6a2 2 0 0 1 1.4.6l4.4 4.4a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2z',
        'lembur' => 'M12 8v4l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
        'payroll' => 'M3 10h18M7 15h4m-6 5h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2z',
        'rekap' => 'M9 17V9m4 8V5m4 12v-6M4 20h16',
        'karyawan' => 'M17 20v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2M10 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm11 10v-2a4 4 0 0 0-3-3.9',
        'aturan' => 'M10.3 4.3a2 2 0 0 1 3.4 0l.5.9a2 2 0 0 0 2 1l1-.1a2 2 0 0 1 1.7 3l-.5.9a2 2 0 0 0 0 2l.5.9a2 2 0 0 1-1.7 3l-1-.1a2 2 0 0 0-2 1l-.5.9a2 2 0 0 1-3.4 0l-.5-.9a2 2 0 0 0-2-1l-1 .1a2 2 0 0 1-1.7-3l.5-.9a2 2 0 0 0 0-2l-.5-.9a2 2 0 0 1 1.7-3l1 .1a2 2 0 0 0 2-1z',
        'audit' => 'M12 8v4m0 4h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
        'scan' => 'M3 7V5a2 2 0 0 1 2-2h2m10 0h2a2 2 0 0 1 2 2v2M3 17v2a2 2 0 0 0 2 2h2m10 0h2a2 2 0 0 0 2-2v-2M7 12h10',
        'slip' => 'M9 14l2 2 4-4m5 6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2z',
        'menu' => 'M4 6h16M4 12h16M4 18h16',
    ];

    // Menu dikelompokkan supaya sidebar tidak jadi satu daftar panjang tanpa
    // hierarki — sepuluh menu berderet sama rata sulit dipindai.
    $grup = $isManajer
        ? [
            'Operasional' => [
                ['route' => 'dashboard', 'label' => 'Hari Ini', 'ikon' => 'hari', 'utama' => true],
                ['route' => 'manajer.roster.index', 'label' => 'Roster', 'match' => 'manajer.roster.*', 'ikon' => 'roster', 'utama' => true],
                ['route' => 'manajer.pengajuan.index', 'label' => 'Pengajuan', 'match' => 'manajer.pengajuan.*', 'ikon' => 'pengajuan', 'utama' => true, 'lencana' => $jumlahPendingGlobal ?? null],
                ['route' => 'manajer.lembur.index', 'label' => 'Lembur', 'match' => 'manajer.lembur.*', 'ikon' => 'lembur'],
            ],
            'Keuangan' => [
                ['route' => 'manajer.payroll.index', 'label' => 'Payroll', 'match' => 'manajer.payroll.*', 'ikon' => 'payroll', 'utama' => true],
                ['route' => 'laporan', 'label' => 'Rekap Absensi', 'ikon' => 'rekap'],
            ],
            'Pengaturan' => [
                ['route' => 'manajer.karyawan.index', 'label' => 'Karyawan', 'match' => 'manajer.karyawan.*', 'ikon' => 'karyawan'],
                ['route' => 'aktivitas', 'label' => 'Aktivitas Scan', 'ikon' => 'scan'],
                ['route' => 'manajer.aturan.index', 'label' => 'Aturan', 'match' => 'manajer.aturan.*', 'ikon' => 'aturan'],
                ['route' => 'manajer.audit.index', 'label' => 'Audit', 'match' => 'manajer.audit.*', 'ikon' => 'audit'],
            ],
        ]
        : [
            'Menu' => [
                ['route' => 'karyawan.beranda', 'label' => 'Beranda', 'ikon' => 'hari', 'utama' => true],
                ['route' => 'karyawan.jadwal', 'label' => 'Jadwal Saya', 'pendek' => 'Jadwal', 'ikon' => 'roster', 'utama' => true],
                ['route' => 'karyawan.absensi', 'label' => 'Absensi Saya', 'pendek' => 'Absensi', 'ikon' => 'rekap', 'utama' => true],
                ['route' => 'karyawan.lembur.index', 'label' => 'Lembur', 'ikon' => 'lembur', 'utama' => true, 'lencana' => $lemburMenunggu ?? null],
                ['route' => 'karyawan.pengajuan.index', 'label' => 'Pengajuan', 'pendek' => 'Ajukan', 'match' => 'karyawan.pengajuan.*', 'ikon' => 'pengajuan', 'utama' => true],
                ['route' => 'karyawan.slip.index', 'label' => 'Slip Gaji', 'pendek' => 'Slip', 'match' => 'karyawan.slip.*', 'ikon' => 'slip', 'utama' => true],
            ],
        ];

    $semua = collect($grup)->flatten(1);
    $aktif = fn (array $item) => request()->routeIs($item['match'] ?? $item['route']);
    $tautanUtama = $semua->filter(fn ($i) => $i['utama'] ?? false)->values();
    $judulAktif = $semua->first(fn ($i) => $aktif($i))['label'] ?? config('app.name');

    $lebar = trim($__env->yieldContent('lebar')) ?: 'max-w-6xl';
@endphp

<div class="flex min-h-full">

    {{-- ----------------------------------------------------------- sidebar --}}
    {{-- Di layar kecil sidebar digeser keluar layar, bukan disembunyikan
         dengan `hidden` — supaya munculnya bisa dianimasikan dan isinya tetap
         ada di DOM untuk pembaca layar. --}}
    <aside id="sidebar"
           class="cetak-sembunyi fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col
                  bg-slate-900 text-slate-300 transition-transform duration-200 lg:translate-x-0">
        <div class="flex h-16 shrink-0 items-center gap-2.5 px-5">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-white text-sm font-bold text-slate-900">
                {{ mb_substr(config('app.name'), 0, 1) }}
            </span>
            <span class="truncate text-base font-semibold text-white">{{ config('app.name') }}</span>
        </div>

        <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-4">
            @foreach ($grup as $namaGrup => $daftar)
                <div>
                    <p class="px-3 pb-2 text-[10px] font-semibold uppercase tracking-widest text-slate-500">
                        {{ $namaGrup }}
                    </p>
                    <div class="space-y-0.5">
                        @foreach ($daftar as $item)
                            <a href="{{ route($item['route']) }}"
                               @class([
                                   'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition',
                                   'bg-white text-slate-900' => $aktif($item),
                                   'text-slate-300 hover:bg-slate-800 hover:text-white' => ! $aktif($item),
                               ])>
                                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="1.8">
                                    <path d="{{ $ikon[$item['ikon']] }}" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <span class="flex-1 truncate">{{ $item['label'] }}</span>
                                @if (! empty($item['lencana']))
                                    <span class="rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold text-white">
                                        {{ $item['lencana'] }}
                                    </span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </nav>

        <div class="shrink-0 border-t border-slate-800 p-3">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-400 transition hover:bg-slate-800 hover:text-white">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4m7 14 5-5-5-5m5 5H9"
                              stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Keluar
                </button>
            </form>
        </div>
    </aside>

    {{-- Latar gelap saat sidebar terbuka di layar kecil. --}}
    <div id="tirai-sidebar" data-panel hidden class="fixed inset-0 z-40 bg-slate-900/50 lg:hidden"></div>

    {{-- --------------------------------------------------------- isi utama --}}
    <div class="flex min-w-0 flex-1 flex-col lg:pl-64">

        <header class="cetak-sembunyi sticky top-0 z-30 border-b border-slate-200 bg-white">
            <div class="flex h-16 items-center justify-between gap-3 px-4 sm:px-6">
                <div class="flex min-w-0 items-center gap-3">
                    <button type="button" data-buka="sidebar" aria-expanded="false" aria-label="Buka menu"
                            class="-ml-1 flex h-10 w-10 items-center justify-center rounded-lg text-slate-600 transition hover:bg-slate-100 lg:hidden">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="{{ $ikon['menu'] }}" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                    <h1 class="truncate text-base font-semibold sm:text-lg">@yield('title', $judulAktif)</h1>
                </div>

                <div class="relative shrink-0">
                    <button type="button" data-buka="menu-akun" aria-expanded="false"
                            class="flex items-center gap-2 rounded-xl py-1.5 pl-1.5 pr-2 transition hover:bg-slate-100">
                        @if ($user?->employee)
                            <x-avatar :employee="$user->employee" ukuran="sm" />
                        @else
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold text-slate-600">
                                {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                            </span>
                        @endif
                        <span class="hidden text-left leading-tight sm:block">
                            <span class="block max-w-32 truncate text-sm font-medium">{{ $user->name }}</span>
                            <span class="block text-xs text-slate-500">{{ $user->role->label() }}</span>
                        </span>
                        <svg class="h-4 w-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>

                    <div id="menu-akun" data-panel hidden
                         class="absolute right-0 z-40 mt-2 w-56 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-lg">
                        <div class="border-b border-slate-100 px-4 py-3">
                            <p class="truncate text-sm font-medium">{{ $user->name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ '@' . $user->username }}</p>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit"
                                    class="w-full px-4 py-3 text-left text-sm font-medium text-red-600 transition hover:bg-red-50">
                                Keluar
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="mx-auto w-full flex-1 px-4 pb-24 pt-5 sm:px-6 sm:pt-6 lg:pb-10 {{ $lebar }}">
            @if (session('status'))
                <div class="pemberitahuan mb-5 flex items-start gap-2.5 border-emerald-200 bg-emerald-50 text-emerald-800">
                    <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span>{{ session('status') }}</span>
                </div>
            @endif

            @if ($errors->any())
                <div class="pemberitahuan mb-5 flex items-start gap-2.5 border-red-200 bg-red-50 text-red-800">
                    <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 8v4m0 4h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <div>
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                </div>
            @endif

            @yield('content')
        </main>
    </div>

    {{-- Bilah bawah tetap ada di ponsel: sidebar bagus untuk layar lebar, tapi
         di ponsel jempol lebih cepat sampai ke bawah layar daripada ke tombol
         menu di pojok atas. --}}
    <nav class="nav-bawah cetak-sembunyi">
        <div class="mx-auto flex max-w-lg">
            @foreach ($tautanUtama as $item)
                <a href="{{ route($item['route']) }}"
                   @class(['nav-bawah-item', 'nav-bawah-aktif' => $aktif($item)])>
                    <span class="relative">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="{{ $aktif($item) ? '2.4' : '1.8' }}">
                            <path d="{{ $ikon[$item['ikon']] }}" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        @if (! empty($item['lencana']))
                            <span class="absolute -right-2 -top-1 min-w-4 rounded-full bg-amber-500 px-1 text-[9px] font-bold leading-4 text-white">
                                {{ $item['lencana'] }}
                            </span>
                        @endif
                    </span>
                    {{ $item['pendek'] ?? $item['label'] }}
                </a>
            @endforeach
        </div>
    </nav>
</div>

</body>
</html>
