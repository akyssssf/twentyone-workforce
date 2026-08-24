@extends('layouts.app')
@section('title', 'Ajukan ' . $type->shortLabel())
@section('lebar', 'max-w-2xl')

@section('content')

<x-judul-halaman :judul="'Ajukan ' . $type->label()"
                 keterangan="Pengganti wajib dipilih dan harus bersedia lebih dulu, baru admin memutuskan."
                 :kembali="route('karyawan.pengajuan.index')" />

<form method="POST" action="{{ route('karyawan.pengajuan.store', $type->value) }}" class="kartu space-y-5 p-5 sm:p-6">
    @csrf

    @switch($type->value)

        {{-- ------------------------------------------------- cuti / izin --}}
        @case('leave')
            @if ($saldoCuti->isNotEmpty())
                <div class="flex flex-wrap gap-x-4 gap-y-1 rounded-xl bg-slate-50 px-3.5 py-2.5 text-sm">
                    <span class="text-slate-500">Sisa saldo</span>
                    @foreach ($saldoCuti as $s)
                        <span class="font-medium">
                            {{ $s->leaveType?->name }}:
                            {{ rtrim(rtrim(number_format($s->remaining(), 1), '0'), '.,') }} hari
                        </span>
                    @endforeach
                </div>
            @endif

            <div>
                <label class="block text-sm font-medium">Jenis</label>
                <select name="leave_type_id" required class="kolom mt-1">
                    @foreach ($leaveTypes as $lt)
                        <option value="{{ $lt->id }}" @selected(old('leave_type_id') == $lt->id)>
                            {{ $lt->name }}{{ $lt->is_paid ? '' : ' (tidak dibayar)' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium">Mulai</label>
                    <input type="date" name="start_date" required value="{{ old('start_date') }}" class="kolom mt-1">
                </div>
                <div>
                    <label class="block text-sm font-medium">Sampai</label>
                    <input type="date" name="end_date" required value="{{ old('end_date') }}" class="kolom mt-1">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium">Serah terima pekerjaan</label>
                <input type="text" name="handover_note" value="{{ old('handover_note') }}"
                       placeholder="Hal yang perlu diteruskan ke pengganti" class="kolom mt-1">
            </div>
            @break

        {{-- ------------------------------------------------------ lembur --}}
        @case('overtime')
            <div>
                <label class="block text-sm font-medium">Tanggal</label>
                <input type="date" name="work_date" required value="{{ old('work_date') }}" class="kolom mt-1">
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium">Mulai</label>
                    <input type="time" name="planned_start" required value="{{ old('planned_start') }}" class="kolom mt-1">
                </div>
                <div>
                    <label class="block text-sm font-medium">Selesai</label>
                    <input type="time" name="planned_end" required value="{{ old('planned_end') }}" class="kolom mt-1">
                </div>
            </div>

            <p class="rounded-xl bg-slate-50 px-3.5 py-2.5 text-xs text-slate-500">
                Lembur harus disetujui admin lebih dulu. Setelah disetujui Anda menerima
                <strong>kode lembur</strong> — selama kode itu belum diaktifkan, lemburnya tidak dibayar.
            </p>
            @break

        {{-- --------------------------------------- tukar shift / libur --}}
        @case('swap')
            <div class="rounded-xl border border-slate-200 p-4">
                <p class="text-sm font-semibold">Mau menukar apa?</p>
                <label class="mt-2 flex items-start gap-2 text-sm">
                    <input type="radio" name="mode" value="shift" class="mt-1" checked
                           onchange="pilihModeTukar()">
                    <span>
                        <span class="font-medium">Tukar shift</span> —
                        rekan mengambil alih shift saya di satu tanggal.
                    </span>
                </label>
                <label class="mt-2 flex items-start gap-2 text-sm">
                    <input type="radio" name="mode" value="libur" class="mt-1"
                           onchange="pilihModeTukar()">
                    <span>
                        <span class="font-medium">Tukar libur</span> —
                        saya dan rekan bertukar hari libur. Dua tanggal sekaligus:
                        saya masuk di hari libur saya, dia libur; dan sebaliknya.
                    </span>
                </label>
            </div>

            <fieldset id="tukar-libur" class="hidden space-y-4" disabled>
                <div>
                    <label class="block text-sm font-medium">Libur saya yang ingin dilepas</label>
                    <select name="requester_assignment_id" class="kolom mt-1">
                        @forelse ($liburSaya as $l)
                            <option value="{{ $l->id }}">
                                {{ $l->work_date->translatedFormat('D, d M Y') }}
                            </option>
                        @empty
                            <option value="">Tidak ada hari libur mendatang di jadwal Anda</option>
                        @endforelse
                    </select>
                </div>

                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <label class="block text-sm font-semibold text-amber-900">
                        Libur rekan yang saya inginkan <span class="text-red-500">*</span>
                    </label>
                    <p class="mb-2 mt-0.5 text-xs text-amber-800">
                        Rekannya ikut dari pilihan ini. Dia harus menyatakan bersedia
                        lebih dulu, lalu manajer yang mengesahkan.
                    </p>
                    <select name="partner_assignment_id" class="kolom">
                        <option value="">— pilih libur rekan —</option>
                        @foreach ($liburRekan as $l)
                            <option value="{{ $l->id }}">
                                {{ $l->employee?->name }} — {{ $l->work_date->translatedFormat('D, d M Y') }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <p class="text-xs text-slate-500">
                    Anda harus terjadwal kerja di tanggal libur rekan, dan dia harus
                    terjadwal kerja di tanggal libur Anda. Kalau salah satunya tidak,
                    pengajuannya ditolak karena tukarnya jadi tidak impas.
                </p>
            </fieldset>

            <fieldset id="tukar-shift" class="space-y-4">
            <div>
                <label class="block text-sm font-medium">Jadwal saya yang ingin ditukar</label>
                <select name="requester_assignment_id" required class="kolom mt-1">
                    @forelse ($jadwalSaya as $j)
                        <option value="{{ $j->id }}">
                            {{ $j->work_date->translatedFormat('D, d M Y') }} — {{ $j->shift?->name }} ({{ $j->division?->name }})
                        </option>
                    @empty
                        <option value="">Tidak ada jadwal mendatang</option>
                    @endforelse
                </select>
            </div>

            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                <label class="block text-sm font-semibold text-amber-900">
                    Tukar dengan siapa? <span class="text-red-500">*</span>
                </label>
                <p class="mb-2 mt-0.5 text-xs text-amber-800">
                    Dia sekaligus jadi pengganti Anda, dan harus menyatakan bersedia lebih dulu.
                </p>
                <select name="partner_employee_id" class="kolom">
                    <option value="">— pilih rekan —</option>
                    @foreach ($rekan as $r)
                        <option value="{{ $r->id }}" @selected(old('partner_employee_id') == $r->id)>
                            {{ $r->name }}{{ $r->primaryDivision() ? ' — ' . $r->primaryDivision()->name : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
            </fieldset>

            {{--
                Bagian yang tidak dipilih di-DISABLE, bukan cuma disembunyikan:
                field yang disabled tidak ikut terkirim sama sekali, jadi tidak
                mungkin tanggal dari mode yang salah ikut terbawa.
            --}}
            <script>
                function pilihModeTukar() {
                    const libur = document.querySelector('input[name="mode"][value="libur"]').checked;

                    document.getElementById('tukar-libur').classList.toggle('hidden', !libur);
                    document.getElementById('tukar-libur').disabled = !libur;
                    document.getElementById('tukar-shift').classList.toggle('hidden', libur);
                    document.getElementById('tukar-shift').disabled = libur;
                }

                pilihModeTukar();
            </script>
            @break

        {{-- ----------------------------------------------------- koreksi --}}
        @case('correction')
            <div>
                <label class="block text-sm font-medium">Tanggal absensi</label>
                <select name="work_date" required class="kolom mt-1">
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
                <select name="correction_type" required class="kolom mt-1">
                    <option value="lupa_masuk">Lupa fingerprint masuk</option>
                    <option value="lupa_pulang">Lupa fingerprint pulang</option>
                    <option value="mesin_error">Mesin error</option>
                    <option value="lainnya">Lainnya</option>
                </select>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium">Jam masuk sebenarnya</label>
                    <input type="datetime-local" name="proposed_check_in" class="kolom mt-1">
                </div>
                <div>
                    <label class="block text-sm font-medium">Jam pulang sebenarnya</label>
                    <input type="datetime-local" name="proposed_check_out" class="kolom mt-1">
                </div>
            </div>
            @break
    @endswitch

    {{-- Pengganti wajib untuk semua jenis. Pada tukar shift, rekan yang dipilih
         di atas sudah berperan sebagai pengganti — tidak ditanya dua kali. --}}
    @if ($type->value !== 'swap')
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
            <label class="block text-sm font-semibold text-amber-900">
                Siapa penggantinya? <span class="text-red-500">*</span>
            </label>
            <p class="mb-2 mt-0.5 text-xs text-amber-800">
                @if ($type->value === 'correction')
                    Rekan yang bisa membenarkan kejadiannya. Dia dimintai konfirmasi lebih dulu.
                @else
                    Dia akan diminta menyatakan bersedia lebih dulu. Admin baru bisa menyetujui setelah itu.
                @endif
            </p>
            <select name="substitute_employee_id" required class="kolom">
                <option value="">— pilih rekan —</option>
                @foreach ($rekan as $r)
                    <option value="{{ $r->id }}" @selected(old('substitute_employee_id') == $r->id)>
                        {{ $r->name }}{{ $r->primaryDivision() ? ' — ' . $r->primaryDivision()->name : '' }}
                    </option>
                @endforeach
            </select>
        </div>
    @endif

    <div>
        <label class="block text-sm font-medium">Alasan <span class="text-red-500">*</span></label>
        <textarea name="reason" rows="3" required minlength="5" class="kolom mt-1">{{ old('reason') }}</textarea>
    </div>

    <button class="btn-utama w-full">Kirim pengajuan</button>
</form>

<div class="kartu mt-4 p-4 text-center">
    <p class="mb-3 text-sm text-slate-500">Perlu dikonfirmasi lebih cepat? Hubungi admin langsung.</p>
    <x-tombol-wa class="w-full sm:w-auto"
                 :pesan="'Halo Admin, saya ' . $employee->name . ' mau konfirmasi pengajuan ' . $type->shortLabel() . '.'" />
</div>

@endsection
