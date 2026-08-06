<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Beranda') &middot; {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50 text-slate-900 antialiased">

@php
    $user = auth()->user();
    $isManajer = $user?->isManagement() ?? false;

    // Menu ditentukan peran, bukan disembunyikan dengan CSS. Yang tidak boleh
    // diakses memang tidak punya rute yang bisa dibuka.
    $tautan = $isManajer
        ? [
            ['route' => 'dashboard', 'label' => 'Hari Ini'],
            ['route' => 'manajer.roster.index', 'label' => 'Roster', 'match' => 'manajer.roster.*'],
            ['route' => 'manajer.pengajuan.index', 'label' => 'Pengajuan', 'match' => 'manajer.pengajuan.*', 'badge' => $jumlahPendingGlobal ?? null],
            ['route' => 'manajer.lembur.index', 'label' => 'Lembur', 'match' => 'manajer.lembur.*'],
            ['route' => 'manajer.payroll.index', 'label' => 'Payroll', 'match' => 'manajer.payroll.*'],
            ['route' => 'laporan', 'label' => 'Rekap'],
            ['route' => 'manajer.karyawan.index', 'label' => 'Karyawan', 'match' => 'manajer.karyawan.*'],
            ['route' => 'manajer.aturan.index', 'label' => 'Aturan', 'match' => 'manajer.aturan.*'],
            ['route' => 'manajer.audit.index', 'label' => 'Audit', 'match' => 'manajer.audit.*'],
        ]
        : [
            ['route' => 'karyawan.beranda', 'label' => 'Beranda'],
            ['route' => 'karyawan.jadwal', 'label' => 'Jadwal Saya'],
            ['route' => 'karyawan.absensi', 'label' => 'Absensi Saya'],
            ['route' => 'karyawan.pengajuan.index', 'label' => 'Pengajuan', 'match' => 'karyawan.pengajuan.*'],
            ['route' => 'karyawan.slip.index', 'label' => 'Slip Gaji', 'match' => 'karyawan.slip.*'],
        ];
@endphp

<div class="min-h-full">
    <nav class="bg-white border-b border-slate-200">
        <div class="mx-auto max-w-[1600px] px-4 sm:px-6 lg:px-8">
            <div class="flex h-16 items-center justify-between gap-4">
                <div class="flex min-w-0 items-center gap-6">
                    <a href="{{ route('beranda') }}" class="shrink-0 text-lg font-semibold tracking-tight">
                        {{ config('app.name') }}
                    </a>

                    <div class="hidden min-w-0 flex-wrap gap-1 lg:flex">
                        @foreach ($tautan as $item)
                            @php $aktif = request()->routeIs($item['match'] ?? $item['route']); @endphp
                            <a href="{{ route($item['route']) }}"
                               @class([
                                   'relative rounded-md px-3 py-2 text-sm font-medium transition whitespace-nowrap',
                                   'bg-slate-900 text-white' => $aktif,
                                   'text-slate-600 hover:bg-slate-100' => ! $aktif,
                               ])>
                                {{ $item['label'] }}
                                @if (! empty($item['badge']))
                                    <span class="ml-1 rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                                        {{ $item['badge'] }}
                                    </span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-4">
                    <div class="hidden text-right leading-tight sm:block">
                        <div class="text-sm font-medium">{{ $user->name }}</div>
                        <div class="text-xs text-slate-500">{{ $user->role->label() }}</div>
                    </div>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit"
                                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100">
                            Keluar
                        </button>
                    </form>
                </div>
            </div>

            {{-- Menu ringkas untuk layar kecil --}}
            <div class="flex gap-1 overflow-x-auto pb-2 lg:hidden">
                @foreach ($tautan as $item)
                    @php $aktif = request()->routeIs($item['match'] ?? $item['route']); @endphp
                    <a href="{{ route($item['route']) }}"
                       @class([
                           'rounded-md px-2.5 py-1.5 text-xs font-medium whitespace-nowrap',
                           'bg-slate-900 text-white' => $aktif,
                           'text-slate-600 bg-slate-100' => ! $aktif,
                       ])>{{ $item['label'] }}</a>
                @endforeach
            </div>
        </div>
    </nav>

    <main class="mx-auto max-w-[1600px] px-4 py-8 sm:px-6 lg:px-8">
        @if (session('status'))
            <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <ul class="list-inside list-disc space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>
</div>

</body>
</html>
