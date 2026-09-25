{{-- Baris-baris satu kategori slip. Dipakai dua kali: di slip gaji dan di
     slip bonus, yang dicetak terpisah tapi harus terbaca sama persis. --}}
@php
    $rp = fn ($n) => number_format((int) $n, 0, ',', '.');
    $tgl = fn ($ymd) => \Illuminate\Support\Carbon::parse($ymd)->translatedFormat('D, d M');
@endphp

<table class="w-full text-sm">
    <tbody>
        @foreach ($items as $item)
            @php
                $snapshot = $item->rule_snapshot ?? [];
                $rincian = $snapshot['rincian'] ?? [];
                $kasbon = $snapshot['kasbon'] ?? null;
            @endphp
            <tr>
                <td class="py-1.5 align-top">
                    {{ $item->label }}
                    @if ($item->rate > 0 && $item->qty != 1)
                        <span class="text-xs text-slate-400">({{ rtrim(rtrim(number_format($item->qty, 2, ',', '.'), '0'), ',') }} × Rp {{ $rp($item->rate) }})</span>
                    @endif

                    {{-- Rincian per tanggal: karyawan bisa mencocokkan tiap
                         baris dengan hari yang dia ingat, bukan cuma menerima
                         satu angka total. --}}
                    @if ($rincian)
                        <ul class="mt-1 space-y-0.5 text-xs text-slate-500">
                            @foreach ($rincian as $r)
                                <li class="flex justify-between gap-3 pr-2">
                                    <span>
                                        {{ $tgl($r['date']) }}
                                        @isset($r['minutes']) &middot; {{ \App\Support\Durasi::menit((int) $r['minutes']) }} @endisset
                                        @if (! empty($r['rule'])) &middot; {{ $r['rule'] }} @endif
                                        @if (! empty($r['note'])) &middot; {{ $r['note'] }} @endif
                                    </span>
                                    <span class="tabular-nums">{{ $rp($r['amount'] ?? 0) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($kasbon)
                        <div class="mt-1 text-xs text-slate-500">
                            Kasbon Rp {{ $rp($kasbon['total']) }}
                            @if (! empty($kasbon['tanggal'])) diterima {{ $tgl($kasbon['tanggal']) }} @endif
                            @if (! empty($kasbon['alasan']) && $kasbon['alasan'] !== 'Kasbon') &middot; {{ $kasbon['alasan'] }} @endif
                        </div>
                    @endif
                </td>
                <td class="py-1.5 text-right align-top tabular-nums">{{ $rp($item->amount) }}</td>
            </tr>
        @endforeach

        @if ($subtotal ?? true)
            <tr class="font-medium">
                <td class="py-1.5">Subtotal</td>
                <td class="py-1.5 text-right tabular-nums">{{ $rp($items->sum('amount')) }}</td>
            </tr>
        @endif
    </tbody>
</table>
