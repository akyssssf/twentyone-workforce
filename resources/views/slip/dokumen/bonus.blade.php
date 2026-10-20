@php
    $rp = fn ($n) => 'Rp '.number_format((int) $n, 0, ',', '.');
    $judul = 'Slip Bonus';
    $warnaJudul = '#92400e';
    $kode = $v['payslip']->code.'-B';
@endphp
<div style="width:100%;height:100%;display:flex;flex-direction:column;background:#fff;color:#111827;font-family:Arial,Helvetica,sans-serif;">
    @include('slip.dokumen._kop')

    <div style="margin-bottom:18px;">
        <div style="background:#f3f4f6;padding:7px 11px;border:1px solid #d1d5db;border-radius:4px 4px 0 0;font-weight:700;font-size:10.5px;text-transform:uppercase;color:#1f2937;">Rincian Bonus</div>
        <div style="border:1px solid #d1d5db;border-top:none;padding:12px 14px;border-radius:0 0 4px 4px;">
            @foreach ($v['bonus'] as $b)
                <div style="{{ $loop->last ? '' : 'margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid #f1f5f9;' }}">
                    <div style="display:flex;justify-content:space-between;gap:10px;font-size:12.5px;">
                        <span style="font-weight:700;">{{ $b['label'] }}@if ($b['rumus']) <span style="font-weight:400;font-size:10px;color:#6b7280;">({{ $b['rumus'] }})</span>@endif</span>
                        <span style="font-weight:700;white-space:nowrap;">{{ $rp($b['jumlah']) }}</span>
                    </div>
                    @if ($b['detail'])
                        <table style="width:100%;border-collapse:collapse;margin-top:7px;font-size:11px;">
                            <tr style="color:#6b7280;font-size:9.5px;text-transform:uppercase;">
                                <td style="padding:2px 0;">Tanggal</td><td style="padding:2px 0;">Lama</td>
                                <td style="padding:2px 0;">Keterangan</td><td style="padding:2px 0;text-align:right;">Jumlah</td>
                            </tr>
                            @foreach ($b['detail'] as $d)
                                <tr style="color:#374151;">
                                    <td style="padding:2px 0;">{{ $d['tanggal'] }}</td>
                                    <td style="padding:2px 0;">{{ $d['lama'] ?: '—' }}</td>
                                    <td style="padding:2px 0;">{{ $d['keterangan'] ?: '—' }}</td>
                                    <td style="padding:2px 0;text-align:right;">{{ $d['jumlah'] !== null ? $rp($d['jumlah']) : '—' }}</td>
                                </tr>
                            @endforeach
                        </table>
                    @elseif ($b['sumber'] === 'manual')
                        <div style="font-size:10.5px;color:#6b7280;margin-top:2px;">Bonus manual, disetujui manajer</div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <div style="background:#78350f;color:#fff;border-radius:4px;padding:16px 20px;margin-bottom:18px;">
        <div style="font-size:10.5px;color:#fde68a;text-transform:uppercase;letter-spacing:2px;margin-bottom:4px;">Total Bonus Diterima</div>
        <div style="font-size:27px;font-weight:700;">{{ $rp($v['totalBonus']) }}</div>
    </div>

    @if ($v['adaLembur'])
        <div style="border:1px solid #d1d5db;border-radius:4px;margin-bottom:16px;">
            <div style="background:#f9fafb;padding:7px 11px;border-bottom:1px solid #d1d5db;font-weight:700;font-size:10.5px;text-transform:uppercase;color:#1f2937;">Rangkuman Lembur</div>
            <div style="padding:12px 15px;display:flex;flex-wrap:wrap;row-gap:9px;font-size:12.5px;">
                @foreach ([['Total jam lembur', $v['jamLembur']], ['Tarif per jam', $rp($v['tarifJam'])], ['Hari kerja terjadwal', $v['hariKerja'].' Hari'], ['Jumlah hari lembur', $v['hariLembur'].' Hari']] as [$k, $n])
                    <div style="width:50%;box-sizing:border-box;display:flex;justify-content:space-between;{{ $loop->odd ? 'padding-right:16px;' : 'padding-left:16px;' }}">
                        <span style="color:#4b5563;">{{ $k }}</span><span style="font-weight:600;">{{ $n }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div style="border:1px dashed #d1d5db;background:#fafafa;border-radius:4px;padding:8px 11px;margin-bottom:12px;font-size:10px;color:#4b5563;line-height:1.55;">
        <div>• Slip ini dokumen terpisah dari Slip Gaji periode yang sama.</div>
        <div>• Bonus dibayarkan di luar Take Home Pay pada Slip Gaji.</div>
        @if ($v['adaLembur'])<div>• Tarif lembur per jam = gaji pokok ÷ hari kerja terjadwal ÷ {{ $v['jamPerHari'] }} jam.</div>@endif
    </div>

    @include('slip.dokumen._tandatangan')
</div>
