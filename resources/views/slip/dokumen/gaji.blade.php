@php
    $rp = fn ($n) => 'Rp '.number_format((int) $n, 0, ',', '.');
    $judul = 'Slip Gaji';
    $warnaJudul = '#1f2937';
    $kode = $v['payslip']->code;
    $kotakHeader = 'background:#f3f4f6;padding:7px 11px;border:1px solid #d1d5db;border-radius:4px 4px 0 0;font-weight:700;font-size:10.5px;text-transform:uppercase;color:#1f2937;';
    $kotakIsi = 'border:1px solid #d1d5db;border-top:none;padding:11px;border-radius:0 0 4px 4px;min-height:120px;';
@endphp
<div style="width:100%;height:100%;display:flex;flex-direction:column;background:#fff;color:#111827;font-family:Arial,Helvetica,sans-serif;">
    @include('slip.dokumen._kop')

    <div style="display:flex;gap:18px;margin-bottom:18px;">
        @foreach ([
            ['Pendapatan', $v['pendapatan'], $v['totalPendapatan'], '#f0fdf4', '#bbf7d0', '#166534', false],
            ['Potongan', $v['potongan'], $v['totalPotongan'], '#fef2f2', '#fecaca', '#991b1b', true],
        ] as [$judulKotak, $baris, $total, $bg, $garis, $teks, $merah])
            <div style="width:50%;display:flex;flex-direction:column;">
                <div style="{{ $kotakHeader }}">{{ $judulKotak }}</div>
                <div style="{{ $kotakIsi }}flex-grow:1;">
                    @forelse ($baris as $b)
                        <div style="margin-bottom:9px;">
                            <div style="display:flex;justify-content:space-between;gap:8px;font-size:12.5px;">
                                <span style="color:#374151;">{{ $b['label'] }}</span>
                                <span style="font-weight:600;white-space:nowrap;color:{{ $merah ? '#dc2626' : '#111827' }};">{{ $rp($b['jumlah']) }}</span>
                            </div>
                            @if ($b['rumus'])
                                <div style="font-size:9.5px;color:#6b7280;margin-top:1px;">{{ $b['rumus'] }}</div>
                            @endif
                            {{-- Rincian: satu baris pendek per kejadian, bukan satu
                                 kalimat panjang yang harus dibaca menyamping. --}}
                            @foreach ($b['detail'] as $d)
                                <div style="display:flex;justify-content:space-between;gap:6px;font-size:9.5px;color:#6b7280;margin-top:2px;padding-left:8px;">
                                    <span>{{ $d['tanggal'] }}@if ($d['lama']) · {{ $d['lama'] }}@endif</span>
                                    @if ($d['jumlah'] !== null)<span>{{ number_format($d['jumlah'], 0, ',', '.') }}</span>@endif
                                </div>
                            @endforeach
                        </div>
                    @empty
                        <div style="font-size:12.5px;color:#9ca3af;">Tidak ada.</div>
                    @endforelse
                </div>
                <div style="margin-top:7px;background:{{ $bg }};border:1px solid {{ $garis }};padding:7px 11px;border-radius:4px;display:flex;justify-content:space-between;font-size:12.5px;font-weight:700;color:{{ $teks }};">
                    <span>Total {{ $judulKotak }}</span><span>{{ $rp($total) }}</span>
                </div>
            </div>
        @endforeach
    </div>

    <div style="background:#111827;color:#fff;border-radius:4px;padding:16px 20px;margin-bottom:18px;">
        <div style="font-size:10.5px;color:#9ca3af;text-transform:uppercase;letter-spacing:2px;margin-bottom:4px;">Total Diterima (Take Home Pay)</div>
        <div style="font-size:27px;font-weight:700;">{{ $rp($v['thp']) }}</div>
    </div>

    <div style="border:1px solid #d1d5db;border-radius:4px;margin-bottom:16px;">
        <div style="background:#f9fafb;padding:7px 11px;border-bottom:1px solid #d1d5db;font-weight:700;font-size:10.5px;text-transform:uppercase;color:#1f2937;">Rangkuman Informasi Kehadiran</div>
        <div style="padding:12px 15px;display:flex;flex-wrap:wrap;row-gap:9px;font-size:12.5px;">
            @foreach ($v['kehadiran'] as $label => $nilai)
                <div style="width:50%;box-sizing:border-box;display:flex;justify-content:space-between;{{ $loop->odd ? 'padding-right:16px;' : 'padding-left:16px;' }}">
                    <span style="color:#4b5563;">{{ $label }}</span><span style="font-weight:600;">{{ $nilai }}</span>
                </div>
            @endforeach
        </div>
    </div>

    <div style="border:1px dashed #d1d5db;background:#fafafa;border-radius:4px;padding:8px 11px;margin-bottom:12px;font-size:10px;color:#4b5563;line-height:1.55;">
        <div style="font-weight:700;color:#374151;margin-bottom:2px;">Keterangan</div>
        @foreach ($v['keterangan'] as $k)<div>• {{ $k }}</div>@endforeach
        @if ($v['adaBonus'])<div>• Bonus {{ $rp($v['totalBonus']) }} dicetak pada Slip Bonus terpisah.</div>@endif
    </div>

    @include('slip.dokumen._tandatangan')
</div>
