@extends('layouts.app')
@section('title', 'Akun Saya')
@section('lebar', 'max-w-md')

@section('content')

@if ($wajib)
    <div class="pemberitahuan mb-5 border-amber-200 bg-amber-50 text-amber-900">
        Kata sandi Anda masih yang dibuatkan admin. Ganti dulu sebelum memakai aplikasi —
        selama belum diganti, orang lain yang sempat melihatnya bisa membuka slip gaji Anda.
    </div>
@endif

<form method="POST" action="{{ route('sandi.update') }}" class="kartu space-y-4 p-5 sm:p-6">
    @csrf

    <h2 class="font-semibold">Ganti Kata Sandi</h2>

    <x-kolom-sandi id="sandi_lama" name="sandi_lama" label="Kata sandi sekarang" autocomplete="current-password" />

    <div>
        <x-kolom-sandi id="password" name="password" label="Kata sandi baru" autocomplete="new-password" :minlength="8" />
        <p class="mt-1 text-xs text-slate-500">Minimal 8 karakter.</p>
    </div>

    <x-kolom-sandi id="password_confirmation" name="password_confirmation" label="Ulangi kata sandi baru" autocomplete="new-password" />

    <button class="btn-utama w-full">Simpan kata sandi</button>
</form>

@unless ($wajib)
    {{-- Ganti nama panggilan sendiri, cuma muncul di luar alur paksa-ganti
         supaya tidak mengalihkan perhatian dari yang wajib diselesaikan
         dulu (ganti sandi). --}}
    <form method="POST" action="{{ route('sandi.username') }}" class="kartu mt-5 space-y-4 p-5 sm:p-6">
        @csrf

        <h2 class="font-semibold">Ganti Nama Panggilan</h2>
        <p class="-mt-2 text-xs text-slate-500">Ini yang dipakai untuk masuk, bukan email.</p>

        <div>
            <label for="username" class="block text-sm font-medium">Nama panggilan</label>
            <input id="username" type="text" name="username" value="{{ old('username', auth()->user()->username) }}"
                   required minlength="3" maxlength="32" pattern="[a-z0-9]+"
                   autocapitalize="none" autocorrect="off" spellcheck="false"
                   class="kolom mt-1">
        </div>

        <button class="btn-netral w-full">Simpan nama panggilan</button>
    </form>
@endunless

@endsection
