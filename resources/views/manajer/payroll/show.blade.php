@extends('layouts.app')
@section('title', 'Payroll ' . $period->code)
@section('lebar', 'max-w-6xl')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
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
                <button class="btn-utama">
                    {{ $run ? 'Hitung ulang' : 'Hitung payroll' }}
                </button>
            </form>
        @endif

        @if ($period->status->value === 'generated')
            <form method="POST" action="{{ route('manajer.payroll.approve', $period) }}">
                @csrf
                <button class="btn-setuju">
                    Setujui &amp; terbitkan slip
                </button>
            </form>
        @endif

        @if ($period->status->value === 'approved')
            <form method="POST" action="{{ route('manajer.payroll.lock', $period) }}"
                  onsubmit="return confirm('Setelah dikunci, absensi dan roster di rentang ini tidak bisa diubah lagi. Lanjutkan?')">
                @csrf
                <button class="btn-netral">
                    Kunci periode
                </button>
            </form>
        @endif
    </div>
</div>

@if ($period->isLocked())
    <div class="pemberitahuan mb-5 border-slate-300 bg-slate-100">
        <p class="font-semibold">Periode terkunci sejak {{ $period->locked_at?->translatedFormat('d M Y H:i') }}.</p>
        <p class="mt-1 text-slate-600">
            Koreksi untuk periode ini sebaiknya dibayar sebagai penyesuaian di periode berikutnya.
            Membuka kunci berarti membatalkan slip yang sudah diterima semua karyawan — hanya untuk kesalahan sistemik.
        </p>
        <form method="POST" action="{{ route('manajer.payroll.reopen', $period) }}" class="mt-3 flex gap-2">
            @csrf
            <input type="text" name="reason" required minlength="10" placeholder="Alasan membuka kunci (wajib, min 10 huruf)"
                   class="kolom flex-1">
            <button class="btn border border-orange-300 bg-white text-orange-700 hover:bg-orange-50">
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

    <div class="mb-6 overflow-hidden kartu">
        <div class="kartu-judul"><h2 class="font-semibold">Slip Gaji</h2></div>
        <div class="tabel-bungkus">
            <table class="tabel">
                <thead>
                    <tr>
                        <th >Karyawan</th>
                        <th class="text-right">Hadir</th>
                        <th class="text-right">Alpha</th>
                        <th class="text-right">Telat</th>
                        <th class="text-right">Lembur</th>
                        <th class="text-right">Pendapatan</th>
                        <th class="text-right">Potongan</th>
                        <th class="text-right">BPJS</th>
                        <th class="text-right">THP</th>
                        <th ></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payslips->sortBy(fn ($p) => $p->employee?->name) as $slip)
                        <tr >
                            <td >
                                <div class="flex items-center gap-2.5">
                                    <x-avatar :employee="$slip->employee" ukuran="sm" />
                                    <span class="font-medium">{{ $slip->employee?->name }}</span>
                                </div>
                            </td>
                            <td class="text-right">{{ $slip->present_days }}</td>
                            <td class="text-right {{ $slip->absent_days > 0 ? 'text-red-600' : 'text-slate-400' }}">{{ $slip->absent_days }}</td>
                            <td class="text-right {{ $slip->late_count > 0 ? 'text-amber-700' : 'text-slate-400' }}">{{ $slip->late_count }}</td>
                            <td class="text-right text-slate-500">{{ $slip->overtime_minutes > 0 ? round($slip->overtime_minutes/60,1).'j' : '—' }}</td>
                            <td class="text-right">{{ number_format($slip->total_earning, 0, ',', '.') }}</td>
                            <td class="text-right text-red-600">{{ number_format($slip->total_deduction, 0, ',', '.') }}</td>
                            <td class="text-right text-red-600">{{ number_format($slip->total_statutory, 0, ',', '.') }}</td>
                            <td class="text-right font-semibold">{{ number_format($slip->take_home_pay, 0, ',', '.') }}</td>
                            <td class="text-right">
                                <a href="{{ route('manajer.payroll.payslip', $slip) }}" class="text-slate-700 hover:underline">Slip</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@else
    <div class="pemberitahuan mb-5 border-sky-200 bg-sky-50 text-sky-900">
        Payroll periode ini belum dihitung. Pastikan absensi sudah final sebelum menekan Hitung payroll.
    </div>
@endif

{{-- Bonus & potongan manual --}}
<div class="kartu">
    <div class="kartu-judul">
        <h2 class="font-semibold">Bonus &amp; Potongan Manual</h2>
        <p class="text-xs text-slate-500">Keduanya wajib beralasan. Setelah menambah, jalankan hitung ulang.</p>
    </div>

    @unless ($period->isLocked())
        <form method="POST" action="{{ route('manajer.payroll.entry', $period) }}" class="flex flex-wrap items-end gap-3 border-b border-slate-100 px-4 py-4">
            @csrf
            <div>
                <label class="label">Karyawan</label>
                <select name="employee_id" required class="kolom mt-1">
                    @foreach ($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="label">Jenis</label>
                <select name="entry_type" required class="kolom mt-1">
                    <option value="bonus">Bonus</option>
                    <option value="deduction">Potongan</option>
                </select>
            </div>
            <div>
                <label class="label">Kategori potongan</label>
                <select name="deduction_type_id" class="kolom mt-1">
                    <option value="">—</option>
                    @foreach ($deductionTypes as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="label">Nominal (Rp)</label>
                <input type="number" name="amount" min="1" required class="kolom mt-1 w-32">
            </div>
            <div class="min-w-[200px] flex-1">
                <label class="label">Alasan (wajib)</label>
                <input type="text" name="reason" required minlength="5" class="kolom mt-1">
            </div>
            <button class="btn-utama">Tambah</button>
        </form>
    @endunless

    <table class="tabel">
        <tbody>
            @forelse ($entries as $e)
                <tr>
                    <td >{{ $e->employee?->name }}</td>
                    <td >
                        <x-status-badge :warna="$e->entry_type === 'bonus' ? 'emerald' : 'red'"
                                        :label="$e->entry_type === 'bonus' ? 'Bonus' : ($e->deductionType?->name ?? 'Potongan')" />
                    </td>
                    <td class="text-slate-500">{{ $e->reason }}</td>
                    <td class="text-right">Rp {{ number_format($e->amount, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-6 text-center text-slate-400">Belum ada entri manual.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
