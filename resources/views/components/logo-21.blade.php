@props(['varian' => 'putih'])

{{--
    Logo 21 Kafe.

    Dua varian karena logonya polos satu warna: "putih" untuk latar gelap,
    "hitam" untuk latar terang. Salah pasang membuatnya lenyap, bukan sekadar
    kurang enak dilihat.
--}}
<img src="{{ asset('img/logo-21-'.$varian.'.png') }}"
     alt="{{ config('app.name') }}"
     {{ $attributes->merge(['class' => 'select-none object-contain']) }}>
