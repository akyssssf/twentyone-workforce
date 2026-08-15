{{--
    Inisial nama aplikasi untuk badge logo.

    Bukan sekadar huruf pertama: nama yang diawali angka seperti "21 Kafe"
    akan menyusut jadi "2" saja dan terbaca seperti potongan yang salah.
    Diambil kata pertamanya, dipangkas dua huruf — "21 Kafe" jadi "21",
    "Absensi Kafe" jadi "Ab".
--}}
{{ mb_strtoupper(mb_substr(strtok(trim((string) config('app.name')), ' ') ?: '?', 0, 2)) }}
