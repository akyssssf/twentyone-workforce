@extends('layouts.app')
@section('title', 'Ganti Kata Sandi')
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

    <div>
        <label for="sandi_lama" class="block text-sm font-medium">Kata sandi sekarang</label>
        <input id="sandi_lama" type="password" name="sandi_lama" required autocomplete="current-password"
               class="kolom mt-1">
    </div>

    <div>
        <label for="password" class="block text-sm font-medium">Kata sandi baru</label>
        <input id="password" type="password" name="password" required autocomplete="new-password"
               minlength="8" class="kolom mt-1">
        <p class="mt-1 text-xs text-slate-500">Minimal 8 karakter.</p>
    </div>

    <div>
        <label for="password_confirmation" class="block text-sm font-medium">Ulangi kata sandi baru</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required
               autocomplete="new-password" class="kolom mt-1">
    </div>

    <button class="btn-utama w-full">Simpan kata sandi</button>
</form>

@endsection
