@extends('layouts.app')

@section('title', 'Libur Saya')
@section('lebar', 'max-w-2xl')

@section('content')

<x-judul-halaman judul="Libur Saya"
                 :keterangan="$bulan->translatedFormat('F Y')" />

@if (session('status'))
    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
        {{ session('status') }}
    </div>
@endif

@error('tanggal')
    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        {{ $message }}
    </div>
@enderror

{{-- Sisa jatah ditaruh paling atas dan paling besar: itu angka yang menentukan
     apakah halaman ini masih ada gunanya hari itu. --}}
<div class="kartu mb-4">
    <div class="flex items-center justify-between px-5 py-4">
        <div>
            <p class="text-sm text-slate-500">Sisa jatah libur {{ $bulan->translatedFormat('F') }}</p>
            <p class="text-3xl font-semibold {{ $sisa > 0 ? 'text-slate-900' : 'text-red-600' }}">
                {{ $sisa }} <span class="text-base font-normal text-slate-500">dari {{ $jatah }} hari</span>
            </p>
        </div>
    </div>

    @if ($terpakai->isNotEmpty())
        <div class="border-t border-slate-100 px-5 py-3">
            <p class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-500">Sudah dipakai</p>
            <ul class="space-y-1 text-sm">
                @foreach ($terpakai as $l)
                    <li>{{ $l->work_date->translatedFormat('l, d F Y') }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>

@if ($konfirmasi)
    {{-- Langkah konfirmasi: belum ada apa pun yang tersimpan sampai tombol di
         bawah ditekan. Ada karena pilihannya berlaku langsung dan tidak bisa
         dibatalkan sendiri — satu salah klik berarti satu hari libur hilang. --}}
    <div class="kartu border-amber-300">
        <div class="border-b border-amber-100 bg-amber-50 px-5 py-4">
            <p class="text-sm font-semibold text-amber-900">Yakin mau libur tanggal ini?</p>
            <p class="mt-1 text-lg font-semibold text-amber-900">
                {{ $konfirmasi->translatedFormat('l, d F Y') }}
            </p>
        </div>

        <div class="px-5 py-4 text-sm text-slate-700">
            <p>
                Setelah dikonfirmasi, sisa jatah {{ $konfirmasi->translatedFormat('F') }} tinggal
                <span class="font-semibold">{{ max(0, ($sisaPilihan ?? $sisa) - 1) }} hari</span>.
            </p>
            <p class="mt-2 text-slate-500">
                Pilihan ini langsung berlaku dan <span class="font-medium text-slate-700">tidak bisa
                dibatalkan sendiri</span>. Kalau ternyata salah, hubungi admin.
            </p>
        </div>

        <div class="flex flex-col gap-2 border-t border-slate-100 px-5 py-4 sm:flex-row">
            <form method="POST" action="{{ route('karyawan.libur.store') }}" class="sm:flex-1">
                @csrf
                <input type="hidden" name="tanggal" value="{{ $konfirmasi->toDateString() }}">
                <input type="hidden" name="konfirmasi" value="1">
                <button class="btn-setuju w-full">Ya, ambil libur tanggal ini</button>
            </form>
            <a href="{{ route('karyawan.libur.index') }}" class="btn-netral sm:flex-1 text-center">Batal</a>
        </div>
    </div>
@elseif ($sisa < 1)
    <div class="kartu px-5 py-6 text-center text-sm text-slate-500">
        Jatah libur bulan ini sudah habis. Jatahnya penuh lagi bulan depan.
    </div>
@elseif ($kandidat->isEmpty())
    <div class="kartu px-5 py-6 text-center text-sm text-slate-500">
        Belum ada jadwal kerja mendatang yang bisa dijadikan libur.
    </div>
@else
    <form method="POST" action="{{ route('karyawan.libur.store') }}" class="kartu">
        @csrf
        <div class="px-5 py-4">
            <label class="block text-sm font-medium">Pilih tanggal libur</label>
            <p class="mb-2 mt-0.5 text-xs text-slate-500">
                Hanya hari yang Anda dijadwalkan kerja yang muncul di sini.
            </p>
            <select name="tanggal" required class="kolom">
                @foreach ($kandidat as $k)
                    <option value="{{ $k->work_date->toDateString() }}">
                        {{ $k->work_date->translatedFormat('l, d F Y') }} — {{ $k->shift?->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="border-t border-slate-100 px-5 py-4">
            <button class="btn-utama w-full sm:w-auto">Lanjut</button>
        </div>
    </form>
@endif

@endsection
