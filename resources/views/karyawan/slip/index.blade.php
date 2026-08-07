@extends('layouts.app')
@section('title', 'Slip Gaji')

@section('content')

<div class="overflow-hidden kartu">
    <table class="tabel">
        <thead>
            <tr>
                <th >Periode</th>
                <th >Dibayar</th>
                <th class="text-right">Take Home Pay</th>
                <th ></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payslips as $slip)
                <tr >
                    <td >{{ $slip->run->period->label() }}</td>
                    <td class="text-slate-500">{{ $slip->run->period->pay_date->translatedFormat('d M Y') }}</td>
                    <td class="text-right font-semibold">Rp {{ number_format($slip->take_home_pay, 0, ',', '.') }}</td>
                    <td class="text-right">
                        <a href="{{ route('karyawan.slip.show', $slip) }}" class="font-medium text-slate-700 hover:underline">Lihat</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">Belum ada slip gaji terbit.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
