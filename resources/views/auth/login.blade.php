<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f172a">
    <title>Masuk &middot; {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50">

<div class="flex min-h-full items-center justify-center px-4 py-10">
    <div class="w-full max-w-sm">

        <div class="mb-7 text-center">
            <span class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-900 text-lg font-bold text-white">
                {{ mb_substr(config('app.name'), 0, 1) }}
            </span>
            <h1 class="text-xl font-semibold tracking-tight">{{ config('app.name') }}</h1>
            <p class="mt-1 text-sm text-slate-500">Masuk dengan nama panggilan Anda</p>
        </div>

        <div class="kartu p-5 sm:p-6">
            @if ($errors->any())
                <div class="pemberitahuan mb-4 border-red-200 bg-red-50 text-red-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="username" class="block text-sm font-medium text-slate-700">Nama panggilan</label>
                    <input id="username" name="username" type="text" required autofocus
                           value="{{ old('username') }}" autocomplete="username"
                           autocapitalize="none" autocorrect="off" spellcheck="false"
                           placeholder="contoh: dian"
                           class="kolom mt-1">
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700">Kata sandi</label>
                    <input id="password" name="password" type="password" required autocomplete="current-password"
                           class="kolom mt-1">
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="remember" value="1"
                           class="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-900">
                    Ingat saya di perangkat ini
                </label>

                <button type="submit" class="btn-utama w-full">Masuk</button>
            </form>
        </div>

        <p class="mt-5 text-center text-xs leading-relaxed text-slate-400">
            Lupa kata sandi? Minta admin untuk mengaturnya ulang.
        </p>
    </div>
</div>

</body>
</html>
