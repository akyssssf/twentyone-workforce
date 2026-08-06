@extends('layouts.app')
@section('title', 'Slip Gaji')

@section('content')
<h1 class="mb-6 text-2xl font-semibold tracking-tight">Slip Gaji Saya</h1>

<div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-2 font-medium">Periode</th>
                <th class="px-4 py-2 font-medium">Dibayar</th>
                <th class="px-4 py-2 text-right font-medium">Take Home Pay</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($payslips as $slip)
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-2 font-medium">{{ $slip->run->period->label() }}</td>
                    <td class="px-4 py-2 text-slate-500">{{ $slip->run->period->pay_date->translatedFormat('d M Y') }}</td>
                    <td class="px-4 py-2 text-right font-semibold">Rp {{ number_format($slip->take_home_pay, 0, ',', '.') }}</td>
                    <td class="px-4 py-2 text-right">
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
