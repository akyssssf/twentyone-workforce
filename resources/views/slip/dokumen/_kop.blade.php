{{-- Kop surat dokumen PDF. SEMUA gaya inline, warna hex: html2canvas tidak bisa
     membaca warna oklch() yang dipakai Tailwind 4, jadi kelas Tailwind di sini
     akan menghasilkan dokumen kosong atau galat saat dijadikan PDF. --}}
<div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #111827;padding-bottom:14px;margin-bottom:18px;">
    <div style="display:flex;align-items:flex-start;gap:12px;">
        <img src="{{ asset('img/logo-21-hitam.png') }}" alt="" style="width:46px;height:46px;object-fit:contain;margin-top:2px;">
        <div>
            <div style="font-size:26px;font-weight:700;line-height:1.1;">21 KAFE</div>
            <div style="font-size:10.5px;color:#4b5563;margin-top:3px;line-height:1.45;">
                JL. LINGKAR, MUNCANG, KEC. BUMIAYU<br>KAB. BREBES, JAWA TENGAH 52273<br>21twentyonekafe@gmail.com
            </div>
        </div>
    </div>
    <div style="text-align:right;">
        <div style="font-size:26px;font-weight:300;color:{{ $warnaJudul }};">{{ $judul }}</div>
        <div style="font-size:10.5px;color:#6b7280;text-transform:uppercase;letter-spacing:1px;margin-top:3px;">Periode: {{ $v['periode'] }}</div>
        <div style="font-size:10px;color:#9ca3af;font-family:Courier,monospace;margin-top:2px;">{{ $kode }}</div>
    </div>
</div>

<div style="display:flex;flex-wrap:wrap;row-gap:6px;margin-bottom:18px;font-size:12.5px;">
    @foreach ([['Nama / PIN', $v['nama'].' ('.$v['pin'].')'], ['ID Karyawan', $v['noInduk']], ['Divisi', $v['divisi']], ['Dibayar', $v['dibayar']]] as [$k, $n])
        <div style="width:50%;display:flex;">
            <span style="width:96px;font-weight:600;color:#4b5563;">{{ $k }}</span>
            <span style="font-weight:700;">: {{ $n }}</span>
        </div>
    @endforeach
</div>
