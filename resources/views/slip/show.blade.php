@extends('layouts.app')
@section('title', 'Slip Gaji')

@section('content')
@php $snap = $payslip->employee_snapshot ?? []; @endphp

<div class="mx-auto max-w-3xl">
    <div class="mb-3 flex items-center justify-between print:hidden">
        <a href="{{ $kembali }}" class="text-sm text-slate-500 hover:underline">&larr; Kembali</a>
        <button onclick="window.print()" class="btn-netral">
            Cetak / Simpan PDF
        </button>
    </div>

    <div class="kartu p-6 print:border-0">
        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-4">
            <div>
                <h1 class="text-lg font-semibold">Slip Gaji</h1>
                <p class="text-sm text-slate-500">{{ config('app.name') }}</p>
            </div>
            <div class="text-right text-sm">
                <div class="font-mono text-xs text-slate-500">{{ $payslip->code }}</div>
                <div>Periode {{ $payslip->run->period->label() }}</div>
                <div class="text-slate-500">Dibayar {{ $payslip->run->period->pay_date->translatedFormat('d M Y') }}</div>
            </div>
        </div>

        {{-- Data karyawan diambil dari snapshot, bukan dari tabel employees.
             Slip yang sudah terbit tidak boleh berubah isinya hanya karena
             yang bersangkutan pindah divisi bulan depan. --}}
        <dl class="grid grid-cols-2 gap-x-6 gap-y-2 border-b border-slate-200 py-4 text-sm">
            <div class="flex"><dt class="w-28 text-slate-500">Nama</dt><dd class="font-medium">{{ $snap['name'] ?? $payslip->employee?->name }}</dd></div>
            <div class="flex"><dt class="w-28 text-slate-500">No. Induk</dt><dd>{{ $snap['employee_no'] ?? '—' }}</dd></div>
            <div class="flex"><dt class="w-28 text-slate-500">Divisi</dt><dd>{{ $snap['division'] ?? '—' }}</dd></div>
            <div class="flex"><dt class="w-28 text-slate-500">PIN mesin</dt><dd>{{ $snap['pin'] ?? '—' }}</dd></div>
        </dl>

        <div class="grid grid-cols-3 gap-3 border-b border-slate-200 py-4 text-sm sm:grid-cols-6">
            @foreach ([
                'Hari kerja' => $payslip->scheduled_days,
                'Hadir' => $payslip->present_days,
                'Alpha' => $payslip->absent_days,
                'Cuti/Izin' => $payslip->leave_days,
                'Telat' => $payslip->late_count . 'x',
                'Lembur' => round($payslip->overtime_minutes / 60, 1) . ' j',
            ] as $label => $nilai)
                <div>
                    <div class="text-xs text-slate-500">{{ $label }}</div>
                    <div class="font-semibold">{{ $nilai }}</div>
                </div>
            @endforeach
        </div>

        @foreach ([
            'earning' => ['Pendapatan', 'text-emerald-700'],
            'deduction' => ['Potongan', 'text-red-700'],
            'statutory' => ['BPJS & Potongan Wajib', 'text-red-700'],
        ] as $kategori => [$judul, $warna])
            @php $items = $payslip->items->where('category', $kategori); @endphp
            @if ($items->isNotEmpty())
                <div class="py-4">
                    <h2 class="mb-2 text-sm font-semibold {{ $warna }}">{{ $judul }}</h2>
                    <table class="w-full text-sm">
                        <tbody>
                            @foreach ($items as $item)
                                <tr>
                                    <td class="py-1.5">
                                        {{ $item->label }}
                                        @if ($item->qty > 1)
                                            <span class="text-xs text-slate-400">({{ rtrim(rtrim(number_format($item->qty, 2), '0'), ',.') }} × {{ number_format($item->rate, 0, ',', '.') }})</span>
                                        @endif
                                    </td>
                                    <td class="py-1.5 text-right tabular-nums">{{ number_format($item->amount, 0, ',', '.') }}</td>
                                </tr>
                            @endforeach
                            <tr class="font-medium">
                                <td class="py-1.5">Subtotal</td>
                                <td class="py-1.5 text-right tabular-nums">{{ number_format($items->sum('amount'), 0, ',', '.') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            @endif
        @endforeach

        <div class="mt-2 flex items-center justify-between rounded-lg bg-slate-900 px-4 py-3 text-white">
            <span class="font-medium">Take Home Pay</span>
            <span class="text-xl font-semibold tabular-nums">Rp {{ number_format($payslip->take_home_pay, 0, ',', '.') }}</span>
        </div>

        <p class="mt-4 text-xs text-slate-400">
            Slip ini dihasilkan sistem pada {{ $payslip->created_at?->translatedFormat('d M Y H:i') }}.
            Angka di sini dibekukan saat payroll dihitung — perubahan tarif setelahnya tidak mengubah slip ini.
        </p>
    </div>
</div>
@endsection
