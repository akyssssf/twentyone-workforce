@extends('layouts.app')
@section('title', 'Payroll ' . $period->code)

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <a href="{{ route('manajer.payroll.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Semua periode</a>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight">Payroll {{ $period->code }}</h1>
        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
            <x-status-badge :warna="$period->status->color()" :label="$period->status->label()" />
            Kerja {{ $period->label() }} &middot; dibayar {{ $period->pay_date->translatedFormat('d M Y') }}
        </p>
    </div>

    <div class="flex flex-wrap gap-2">
        @if ($period->status->canGenerate())
            <form method="POST" action="{{ route('manajer.payroll.generate', $period) }}">
                @csrf
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                    {{ $run ? 'Hitung ulang' : 'Hitung payroll' }}
                </button>
            </form>
        @endif

        @if ($period->status->value === 'generated')
            <form method="POST" action="{{ route('manajer.payroll.approve', $period) }}">
                @csrf
                <button class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                    Setujui &amp; terbitkan slip
                </button>
            </form>
        @endif

        @if ($period->status->value === 'approved')
            <form method="POST" action="{{ route('manajer.payroll.lock', $period) }}"
                  onsubmit="return confirm('Setelah dikunci, absensi dan roster di rentang ini tidak bisa diubah lagi. Lanjutkan?')">
                @csrf
                <button class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium hover:bg-slate-50">
                    Kunci periode
                </button>
            </form>
        @endif
    </div>
</div>

@if ($period->isLocked())
    <div class="mb-6 rounded-lg border border-slate-300 bg-slate-100 px-4 py-3 text-sm">
        <p class="font-semibold">Periode terkunci sejak {{ $period->locked_at?->translatedFormat('d M Y H:i') }}.</p>
        <p class="mt-1 text-slate-600">
            Koreksi untuk periode ini sebaiknya dibayar sebagai penyesuaian di periode berikutnya.
            Membuka kunci berarti membatalkan slip yang sudah diterima semua karyawan — hanya untuk kesalahan sistemik.
        </p>
        <form method="POST" action="{{ route('manajer.payroll.reopen', $period) }}" class="mt-3 flex gap-2">
            @csrf
            <input type="text" name="reason" required minlength="10" placeholder="Alasan membuka kunci (wajib, min 10 huruf)"
                   class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <button class="rounded-lg border border-orange-300 bg-white px-4 py-2 text-sm font-medium text-orange-700 hover:bg-orange-50">
                Buka kunci
            </button>
        </form>
    </div>
@endif

@if ($run)
    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <x-kartu label="Karyawan" :nilai="$run->employee_count" />
        <x-kartu label="Total THP" :nilai="'Rp '.number_format($run->total_take_home_pay, 0, ',', '.')" warna="text-emerald-600" />
        <x-kartu label="Versi" :nilai="$run->version" keterangan="versi sebelumnya tetap tersimpan" />
        <x-kartu label="Dihitung" :nilai="$run->finished_at?->format('d/m H:i') ?? '—'" />
    </div>

    <div class="mb-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-4 py-3"><h2 class="font-semibold">Slip Gaji</h2></div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">Karyawan</th>
                        <th class="px-4 py-2 text-right font-medium">Hadir</th>
                        <th class="px-4 py-2 text-right font-medium">Alpha</th>
                        <th class="px-4 py-2 text-right font-medium">Telat</th>
                        <th class="px-4 py-2 text-right font-medium">Lembur</th>
                        <th class="px-4 py-2 text-right font-medium">Pendapatan</th>
                        <th class="px-4 py-2 text-right font-medium">Potongan</th>
                        <th class="px-4 py-2 text-right font-medium">BPJS</th>
                        <th class="px-4 py-2 text-right font-medium">THP</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($payslips->sortBy(fn ($p) => $p->employee?->name) as $slip)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2 font-medium">{{ $slip->employee?->name }}</td>
                            <td class="px-4 py-2 text-right">{{ $slip->present_days }}</td>
                            <td class="px-4 py-2 text-right {{ $slip->absent_days > 0 ? 'text-red-600' : 'text-slate-400' }}">{{ $slip->absent_days }}</td>
                            <td class="px-4 py-2 text-right {{ $slip->late_count > 0 ? 'text-amber-700' : 'text-slate-400' }}">{{ $slip->late_count }}</td>
                            <td class="px-4 py-2 text-right text-slate-500">{{ $slip->overtime_minutes > 0 ? round($slip->overtime_minutes/60,1).'j' : '—' }}</td>
                            <td class="px-4 py-2 text-right">{{ number_format($slip->total_earning, 0, ',', '.') }}</td>
                            <td class="px-4 py-2 text-right text-red-600">{{ number_format($slip->total_deduction, 0, ',', '.') }}</td>
                            <td class="px-4 py-2 text-right text-red-600">{{ number_format($slip->total_statutory, 0, ',', '.') }}</td>
                            <td class="px-4 py-2 text-right font-semibold">{{ number_format($slip->take_home_pay, 0, ',', '.') }}</td>
                            <td class="px-4 py-2 text-right">
                                <a href="{{ route('manajer.payroll.payslip', $slip) }}" class="text-slate-700 hover:underline">Slip</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@else
    <div class="mb-6 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
        Payroll periode ini belum dihitung. Pastikan absensi sudah final sebelum menekan Hitung payroll.
    </div>
@endif

{{-- Bonus & potongan manual --}}
<div class="rounded-xl border border-slate-200 bg-white">
    <div class="border-b border-slate-200 px-4 py-3">
        <h2 class="font-semibold">Bonus &amp; Potongan Manual</h2>
        <p class="text-xs text-slate-500">Keduanya wajib beralasan. Setelah menambah, jalankan hitung ulang.</p>
    </div>

    @unless ($period->isLocked())
        <form method="POST" action="{{ route('manajer.payroll.entry', $period) }}" class="flex flex-wrap items-end gap-3 border-b border-slate-100 px-4 py-4">
            @csrf
            <div>
                <label class="block text-xs font-medium text-slate-500">Karyawan</label>
                <select name="employee_id" required class="mt-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                    @foreach ($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500">Jenis</label>
                <select name="entry_type" required class="mt-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                    <option value="bonus">Bonus</option>
                    <option value="deduction">Potongan</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500">Kategori potongan</label>
                <select name="deduction_type_id" class="mt-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                    <option value="">—</option>
                    @foreach ($deductionTypes as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500">Nominal (Rp)</label>
                <input type="number" name="amount" min="1" required class="mt-1 w-32 rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
            </div>
            <div class="min-w-[200px] flex-1">
                <label class="block text-xs font-medium text-slate-500">Alasan (wajib)</label>
                <input type="text" name="reason" required minlength="5" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
            </div>
            <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Tambah</button>
        </form>
    @endunless

    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <tbody class="divide-y divide-slate-100">
            @forelse ($entries as $e)
                <tr>
                    <td class="px-4 py-2 font-medium">{{ $e->employee?->name }}</td>
                    <td class="px-4 py-2">
                        <x-status-badge :warna="$e->entry_type === 'bonus' ? 'emerald' : 'red'"
                                        :label="$e->entry_type === 'bonus' ? 'Bonus' : ($e->deductionType?->name ?? 'Potongan')" />
                    </td>
                    <td class="px-4 py-2 text-slate-500">{{ $e->reason }}</td>
                    <td class="px-4 py-2 text-right font-medium">Rp {{ number_format($e->amount, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-6 text-center text-slate-400">Belum ada entri manual.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
