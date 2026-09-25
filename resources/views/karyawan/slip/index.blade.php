@extends('layouts.app')
@section('title', 'Slip Gaji')

@section('content')

<div class="overflow-hidden kartu">
    <div class="tabel-bungkus">
    <table class="tabel">
        <thead>
            <tr>
                <th >Periode</th>
                <th >Dibayar</th>
                <th class="text-right">Take Home Pay</th>
                <th class="text-right">Bonus</th>
                <th ></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payslips as $slip)
                <tr >
                    <td >{{ $slip->run->period->label() }}</td>
                    <td class="text-slate-500">{{ $slip->run->period->pay_date->translatedFormat('d M Y') }}</td>
                    <td class="text-right font-semibold">Rp {{ number_format($slip->take_home_pay, 0, ',', '.') }}</td>
                    <td class="text-right {{ $slip->total_bonus > 0 ? 'text-emerald-700' : 'text-slate-400' }}">
                        {{ $slip->total_bonus > 0 ? 'Rp '.number_format($slip->total_bonus, 0, ',', '.') : '—' }}
                    </td>
                    <td class="text-right">
                        <a href="{{ route('karyawan.slip.show', $slip) }}" class="font-medium text-slate-700 hover:underline">Lihat</a>
                        @if ($slip->total_bonus > 0)
                            <a href="{{ route('karyawan.slip.bonus', $slip) }}" class="ml-3 font-medium text-amber-700 hover:underline">Slip bonus</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">Belum ada slip gaji terbit.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
