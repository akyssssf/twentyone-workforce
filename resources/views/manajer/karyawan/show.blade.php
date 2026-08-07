@extends('layouts.app')
@section('title', $employee->name)
@section('lebar', 'max-w-3xl')

@section('content')

<x-judul-halaman :judul="$employee->name" :kembali="route('manajer.karyawan.index')">
    <x-slot:keterangan>
        {{ $employee->employee_no }} &middot; {{ ucfirst($employee->employment_status) }}
    </x-slot:keterangan>
</x-judul-halaman>

<div class="mb-5 flex items-center gap-4">
    <x-avatar :employee="$employee" ukuran="lg" :bisa-diklik="true" />
    <div class="flex flex-wrap gap-2">
        @unless ($employee->tracks_attendance)
            <x-status-badge warna="indigo" label="Tidak diabsen" />
        @endunless
        @foreach ($employee->divisions as $d)
            <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium">
                <span class="h-2 w-2 rounded-full" style="background: {{ $d->color }}"></span>
                {{ $d->name }}{{ $d->pivot->is_primary ? '' : ' (bantu)' }}
            </span>
        @endforeach
    </div>
</div>

{{-- Sandi baru muncul sekali di sini. Setelah halaman ini ditinggalkan, tidak
     ada cara melihatnya lagi — yang tersimpan cuma hash-nya. --}}
@if (session('sandi_baru'))
    <div class="kartu mb-5 border-emerald-300 bg-emerald-50 p-4 text-center">
        <p class="text-xs font-medium uppercase tracking-wide text-emerald-700">
            Kata sandi baru untuk "{{ session('sandi_untuk') }}"
        </p>
        <p class="my-1.5 font-mono text-3xl font-bold tracking-[0.2em] text-emerald-900">{{ session('sandi_baru') }}</p>
        <p class="text-xs text-emerald-700">Catat atau bacakan sekarang — tidak bisa dilihat lagi setelah halaman ini ditutup.</p>
    </div>
@endif

<div class="space-y-5">

    {{-- Akun --}}
    <div class="kartu">
        <div class="kartu-judul">
            <div>
                <h2 class="font-semibold">Akun Login</h2>
                <p class="text-xs text-slate-500">Karyawan masuk pakai nama panggilan, bukan email.</p>
            </div>
        </div>

        @if ($employee->user)
            <div class="kartu-isi space-y-4">
                <form method="POST" action="{{ route('manajer.karyawan.username', $employee) }}"
                      class="flex flex-col gap-2 sm:flex-row sm:items-end">
                    @csrf
                    <div class="flex-1">
                        <label class="label">Nama panggilan</label>
                        <input type="text" name="username" value="{{ $employee->user->username }}"
                               required minlength="3" maxlength="32" pattern="[a-z0-9]+"
                               autocapitalize="none" autocorrect="off" spellcheck="false"
                               class="kolom mt-1">
                    </div>
                    <button class="btn-netral">Simpan</button>
                </form>

                <div class="flex flex-col gap-2 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-slate-500">
                        Kata sandi tidak bisa dilihat. Kalau lupa, atur ulang dan bacakan yang baru.
                        @if ($employee->user->must_change_password)
                            <span class="mt-1 block text-xs text-amber-700">Belum pernah diganti sendiri oleh yang bersangkutan.</span>
                        @endif
                    </p>
                    <form method="POST" action="{{ route('manajer.karyawan.sandi', $employee) }}"
                          onsubmit="return confirm('Atur ulang kata sandi {{ $employee->name }}? Sandi lama langsung tidak berlaku.')">
                        @csrf
                        <button class="btn-netral w-full sm:w-auto">Atur ulang sandi</button>
                    </form>
                </div>
            </div>
        @else
            <x-kosong pesan="Karyawan ini belum punya akun login." />
        @endif
    </div>

    {{-- Data --}}
    <div class="kartu">
        <div class="kartu-judul"><h2 class="font-semibold">Data</h2></div>
        <dl class="divide-y divide-slate-50 text-sm">
            <div class="flex gap-3 px-4 py-3 sm:px-5"><dt class="w-36 shrink-0 text-slate-500">No. Induk</dt><dd>{{ $employee->employee_no }}</dd></div>
            <div class="flex gap-3 px-4 py-3 sm:px-5"><dt class="w-36 shrink-0 text-slate-500">Bergabung</dt><dd>{{ $employee->joined_at?->translatedFormat('d M Y') ?? '—' }}</dd></div>
            <div class="flex gap-3 px-4 py-3 sm:px-5"><dt class="w-36 shrink-0 text-slate-500">Shift preferensi</dt><dd>{{ $employee->defaultShift?->name ?? '—' }}</dd></div>
            <div class="flex gap-3 px-4 py-3 sm:px-5"><dt class="w-36 shrink-0 text-slate-500">Gaji pokok</dt>
                <dd class="tabular-nums">Rp {{ number_format($employee->baseSalaryOn(now()), 0, ',', '.') }}</dd></div>
        </dl>
    </div>

    {{-- Absensi --}}
    <div class="kartu">
        <div class="kartu-judul">
            <div>
                <h2 class="font-semibold">Absensi</h2>
                <p class="text-xs text-slate-500">
                    Admin tetap pegawai aktif dan tetap digaji, tapi tidak menempel jari di mesin.
                </p>
            </div>
        </div>

        <form method="POST" action="{{ route('manajer.karyawan.absensi', $employee) }}" class="kartu-isi">
            @csrf
            <div class="flex flex-wrap items-center justify-between gap-3">
                <label class="flex flex-1 items-start gap-3 text-sm">
                    <input type="checkbox" name="tracks_attendance" value="1" @checked($employee->tracks_attendance)
                           class="mt-0.5 h-4 w-4 rounded border-slate-300">
                    <span>
                        Ikut diabsen &amp; dijadwalkan di roster
                        <span class="mt-0.5 block text-xs text-slate-500">
                            Matikan untuk admin. Kalau tetap diabsen, dia tercatat Alpha setiap hari dan kena potongan.
                        </span>
                    </span>
                </label>
                <button class="btn-utama w-full sm:w-auto">Simpan</button>
            </div>
        </form>
    </div>

    {{-- PIN mesin --}}
    <div class="kartu">
        <div class="kartu-judul">
            <div>
                <h2 class="font-semibold">Pemetaan PIN Mesin</h2>
                <p class="text-xs text-slate-500">Berperiode — PIN yang berpindah orang tidak menarik riwayat lama ikut pindah.</p>
            </div>
        </div>
        <div class="tabel-bungkus">
            <table class="tabel">
                <tbody>
                    @foreach ($employee->devices as $d)
                        <tr>
                            <td class="font-mono">{{ $d->pin }}</td>
                            <td class="text-slate-500">{{ $d->cloud_id }}</td>
                            <td class="text-slate-500">
                                {{ $d->valid_from->translatedFormat('d M Y') }} –
                                {{ $d->valid_to?->translatedFormat('d M Y') ?? 'sekarang' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Divisi --}}
    <div class="kartu">
        <div class="kartu-judul"><h2 class="font-semibold">Divisi &amp; Kompetensi</h2></div>
        <form method="POST" action="{{ route('manajer.karyawan.divisi', $employee) }}" class="kartu-isi space-y-2">
            @csrf
            @php $dimiliki = $employee->divisions->pluck('id')->all(); $utama = $employee->primaryDivision()?->id; @endphp
            @foreach ($divisions as $d)
                <div class="flex items-center gap-3 rounded-xl px-2 py-2 transition hover:bg-slate-50">
                    <input type="checkbox" id="div{{ $d->id }}" name="divisions[]" value="{{ $d->id }}"
                           @checked(in_array($d->id, $dimiliki)) class="h-4 w-4 rounded border-slate-300">
                    <span class="h-3 w-3 shrink-0 rounded-full" style="background: {{ $d->color }}"></span>
                    <label for="div{{ $d->id }}" class="flex-1 text-sm">{{ $d->name }}</label>
                    <label class="flex items-center gap-1.5 text-xs text-slate-500">
                        <input type="radio" name="primary" value="{{ $d->id }}" @checked($utama === $d->id)
                               class="h-4 w-4 border-slate-300">
                        utama
                    </label>
                </div>
            @endforeach
            <button class="btn-utama mt-2 w-full sm:w-auto">Simpan divisi</button>
        </form>
    </div>
</div>

@endsection
