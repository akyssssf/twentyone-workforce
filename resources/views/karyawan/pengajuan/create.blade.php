@extends('layouts.app')
@section('title', 'Ajukan ' . $type->shortLabel())

@section('content')
<div class="mx-auto max-w-2xl">
    <a href="{{ route('karyawan.pengajuan.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Kembali</a>
    <h1 class="mb-6 mt-2 text-2xl font-semibold tracking-tight">Ajukan {{ $type->label() }}</h1>

    <form method="POST" action="{{ route('karyawan.pengajuan.store', $type->value) }}"
          class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        @csrf

        @switch($type->value)
            @case('leave')
                @if ($saldoCuti->isNotEmpty())
                    <div class="rounded-lg bg-slate-50 px-3 py-2 text-sm">
                        <span class="text-slate-500">Sisa saldo:</span>
                        @foreach ($saldoCuti as $s)
                            <span class="ml-2 font-medium">{{ $s->leaveType?->name }}: {{ rtrim(rtrim(number_format($s->remaining(), 1), '0'), '.,') }} hari</span>
                        @endforeach
                    </div>
                @endif

                <div>
                    <label class="block text-sm font-medium">Jenis</label>
                    <select name="leave_type_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($leaveTypes as $lt)
                            <option value="{{ $lt->id }}">{{ $lt->name }}{{ $lt->is_paid ? '' : ' (tidak dibayar)' }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium">Mulai</label>
                        <input type="date" name="start_date" required value="{{ old('start_date') }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Sampai</label>
                        <input type="date" name="end_date" required value="{{ old('end_date') }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium">Serah terima pekerjaan</label>
                    <input type="text" name="handover_note" value="{{ old('handover_note') }}"
                           placeholder="Siapa yang menggantikan tugas Anda?"
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                @break

            @case('overtime')
                <div>
                    <label class="block text-sm font-medium">Tanggal</label>
                    <input type="date" name="work_date" required value="{{ old('work_date') }}"
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium">Mulai</label>
                        <input type="time" name="planned_start" required value="{{ old('planned_start') }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Selesai</label>
                        <input type="time" name="planned_end" required value="{{ old('planned_end') }}"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>
                </div>
                <p class="text-xs text-slate-500">
                    Lembur harus disetujui manager lebih dulu. Waktu setelah jam pulang tanpa persetujuan tidak dihitung lembur.
                </p>
                @break

            @case('swap')
                <div>
                    <label class="block text-sm font-medium">Jadwal saya yang ingin ditukar</label>
                    <select name="requester_assignment_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @forelse ($jadwalSaya as $j)
                            <option value="{{ $j->id }}">
                                {{ $j->work_date->translatedFormat('D, d M Y') }} — {{ $j->shift?->name }} ({{ $j->division?->name }})
                            </option>
                        @empty
                            <option value="">Tidak ada jadwal mendatang</option>
                        @endforelse
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium">Tukar dengan</label>
                    <select name="partner_employee_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($rekan as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach
                    </select>
                </div>
                <p class="text-xs text-slate-500">Rekan harus menerima dulu, baru manager memutuskan.</p>
                @break

            @case('correction')
                <div>
                    <label class="block text-sm font-medium">Tanggal absensi</label>
                    <select name="work_date" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @forelse ($absensiTerakhir as $a)
                            <option value="{{ $a->work_date->toDateString() }}">
                                {{ $a->work_date->translatedFormat('D, d M') }} —
                                masuk {{ $a->check_in_at?->format('H:i') ?? 'kosong' }},
                                pulang {{ $a->check_out_at?->format('H:i') ?? 'kosong' }}
                                ({{ $a->status->label() }})
                            </option>
                        @empty
                            <option value="">Tidak ada catatan 30 hari terakhir</option>
                        @endforelse
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium">Kasus</label>
                    <select name="correction_type" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="lupa_masuk">Lupa fingerprint masuk</option>
                        <option value="lupa_pulang">Lupa fingerprint pulang</option>
                        <option value="mesin_error">Mesin error</option>
                        <option value="lainnya">Lainnya</option>
                    </select>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium">Jam masuk sebenarnya</label>
                        <input type="datetime-local" name="proposed_check_in"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Jam pulang sebenarnya</label>
                        <input type="datetime-local" name="proposed_check_out"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>
                </div>
                @break
        @endswitch

        <div>
            <label class="block text-sm font-medium">Alasan <span class="text-red-500">*</span></label>
            <textarea name="reason" rows="3" required minlength="5"
                      class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('reason') }}</textarea>
        </div>

        <button class="w-full rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-slate-800">
            Kirim pengajuan
        </button>
    </form>
</div>
@endsection
