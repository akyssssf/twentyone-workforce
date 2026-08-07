@extends('layouts.app')
@section('title', 'Aturan')

@section('content')
<h1 class="mb-2 text-2xl font-semibold tracking-tight">Aturan &amp; Setelan</h1>
<p class="mb-6 text-sm text-slate-500">
    Semua nominal di bawah ini bisa diubah tanpa mengubah kode. Perubahan berlaku untuk perhitungan berikutnya —
    slip gaji yang sudah terbit tetap memakai tarif yang berlaku saat itu.
</p>

<div class="space-y-6">
    @foreach ($ruleSets as $rs)
        <div class="kartu">
            <div class="kartu-judul">
                <h2 class="font-semibold">{{ $rs->type->label() }}</h2>
                <p class="text-xs text-slate-500">
                    {{ $rs->name }} &middot; berlaku sejak {{ $rs->effective_from->translatedFormat('d M Y') }}
                </p>
            </div>

            <form method="POST" action="{{ route('manajer.aturan.tier', $rs) }}" class="px-5 py-4">
                @csrf
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="pb-2 font-medium">Tingkatan</th>
                            <th class="pb-2 font-medium">Perhitungan</th>
                            <th class="pb-2 text-right font-medium">Nilai</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rs->tiers as $i => $tier)
                            <tr>
                                <td class="py-2">
                                    {{ $tier->label ?? ($tier->min_value . '–' . ($tier->max_value ?? '∞')) }}
                                    <input type="hidden" name="tiers[{{ $i }}][id]" value="{{ $tier->id }}">
                                </td>
                                <td class="py-2 text-slate-500">
                                    @switch($tier->calc_type)
                                        @case('flat') Rupiah tetap @break
                                        @case('daily_rate') × tarif harian @break
                                        @case('hourly_multiplier') × tarif per jam @break
                                        @case('percent_of_base') % dari gaji pokok @break
                                    @endswitch
                                </td>
                                <td class="py-2 text-right">
                                    <input type="number" step="0.01" min="0" name="tiers[{{ $i }}][value]"
                                           value="{{ rtrim(rtrim(number_format((float) $tier->value, 2, '.', ''), '0'), '.') }}"
                                           class="kolom w-32 text-right">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <button class="btn-utama mt-3">Simpan</button>
            </form>
        </div>
    @endforeach

    <div class="kartu">
        <div class="kartu-judul"><h2 class="font-semibold">Setelan Operasional</h2></div>
        <form method="POST" action="{{ route('manajer.setelan.update') }}" class="px-5 py-4">
            @csrf
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($settings as $key => $value)
                    <div>
                        <label class="label">{{ $key }}</label>
                        @if (is_bool($value))
                            <select name="settings[{{ $key }}]" class="kolom mt-1">
                                <option value="true" @selected($value)>Ya</option>
                                <option value="false" @selected(! $value)>Tidak</option>
                            </select>
                        @else
                            <input type="text" name="settings[{{ $key }}]" value="{{ $value }}"
                                   class="kolom mt-1">
                        @endif
                    </div>
                @endforeach
            </div>
            <button class="btn-utama mt-4">Simpan setelan</button>
        </form>
    </div>
</div>
@endsection
