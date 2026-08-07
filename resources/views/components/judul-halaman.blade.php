@props(['judul' => null, 'keterangan' => null, 'kembali' => null])

{{-- Judul halaman sekarang tinggal di bilah atas (lihat layouts/app), jadi di
     sini tidak diulang. Yang tersisa cuma tautan kembali, keterangan, dan
     tombol aksi — mengulang judul dua kali di layar ponsel memakan tinggi yang
     seharusnya jadi isi. --}}
<div class="mb-4 sm:mb-5">
    @if ($kembali)
        <a href="{{ $kembali }}" class="mb-2 inline-flex items-center gap-1 text-sm text-slate-500 transition hover:text-slate-900">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="m15 18-6-6 6-6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            Kembali
        </a>
    @endif

    @if ($keterangan || isset($aksi))
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-slate-500">{{ $keterangan }}</p>

            @if (isset($aksi))
                <div class="flex flex-wrap gap-2">{{ $aksi }}</div>
            @endif
        </div>
    @endif
</div>
