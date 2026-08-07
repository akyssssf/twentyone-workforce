@extends('layouts.app')
@section('title', 'Pengajuan ' . $pengajuan->code)

@section('content')
<div class="mx-auto max-w-3xl">
    <a href="{{ route('manajer.pengajuan.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Kembali</a>

    <div class="mt-3 kartu">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div class="flex items-center gap-3">
                <x-avatar :employee="$pengajuan->employee" ukuran="md" :bisa-diklik="true" />
                <div>
                    <h1 class="text-lg font-semibold">{{ $pengajuan->type->label() }}</h1>
                    <p class="text-sm text-slate-500">
                        {{ $pengajuan->code }} &middot; {{ $pengajuan->employee?->name }} &middot;
                        diajukan {{ $pengajuan->submitted_at?->translatedFormat('d M Y H:i') }}
                    </p>
                </div>
            </div>
            <x-status-badge :warna="$pengajuan->status->color()" :label="$pengajuan->status->label()" />
        </div>

        <dl class="divide-y divide-slate-100 text-sm">
            @php $d = $pengajuan->detail(); @endphp

            @switch($pengajuan->type->value)
                @case('leave')
                    <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">Jenis</dt><dd class="font-medium">{{ $d?->leaveType?->name }}</dd></div>
                    <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">Tanggal</dt><dd>{{ $d?->start_date?->translatedFormat('d M Y') }} – {{ $d?->end_date?->translatedFormat('d M Y') }}</dd></div>
                    <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">Jumlah</dt><dd>{{ $d?->total_days }} hari</dd></div>
                    @if ($d?->handover_note)
                        <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">Serah terima</dt><dd>{{ $d->handover_note }}</dd></div>
                    @endif
                    @break

                @case('overtime')
                    <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">Tanggal</dt><dd>{{ $d?->work_date?->translatedFormat('d M Y') }}</dd></div>
                    <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">Rencana jam</dt><dd>{{ substr($d?->planned_start ?? '', 0, 5) }} – {{ substr($d?->planned_end ?? '', 0, 5) }} ({{ $d?->planned_minutes }} menit)</dd></div>
                    <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">Diinisiasi</dt><dd>{{ $d?->initiated_by === 'manager' ? 'Manager' : 'Karyawan' }}</dd></div>
                    @if ($d?->is_backdated)
                        <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">Catatan</dt>
                            <dd class="text-amber-700">Approval susulan — tanggalnya sudah lewat.</dd></div>
                    @endif
                    @break

                @case('swap')
                    <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">Jadwal pengaju</dt>
                        <dd>{{ $d?->requesterAssignment?->work_date?->translatedFormat('d M Y') }} — {{ $d?->requesterAssignment?->shift?->name }}</dd></div>
                    <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">Rekan</dt><dd class="font-medium">{{ $d?->partner?->name }}</dd></div>
                    <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">Jawaban rekan</dt>
                        <dd>{{ $d?->partner_accepted_at ? 'Menerima ' . $d->partner_accepted_at->translatedFormat('d M H:i') : ($d?->partner_rejected_at ? 'Menolak' : 'Belum menjawab') }}</dd></div>
                    @break

                @case('correction')
                    <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">Tanggal</dt><dd>{{ $d?->work_date?->translatedFormat('d M Y') }}</dd></div>
                    <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">Kasus</dt><dd>{{ str_replace('_', ' ', $d?->correction_type ?? '') }}</dd></div>
                    <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">Usulan masuk</dt><dd>{{ $d?->proposed_check_in?->format('H:i') ?? '—' }}</dd></div>
                    <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">Usulan pulang</dt><dd>{{ $d?->proposed_check_out?->format('H:i') ?? '—' }}</dd></div>
                    @break
            @endswitch

            <div class="flex px-5 py-3"><dt class="w-40 shrink-0 text-slate-500">Alasan</dt><dd>{{ $d?->reason }}</dd></div>

            {{-- Pengganti: penentu apakah pengajuan ini boleh disetujui. --}}
            <div class="flex px-5 py-3">
                <dt class="w-40 shrink-0 text-slate-500">Pengganti</dt>
                <dd class="min-w-0">
                    @if ($pengajuan->substitute)
                        <div class="flex items-center gap-2">
                            <x-avatar :employee="$pengajuan->substitute" ukuran="xs" />
                            <span class="font-medium">{{ $pengajuan->substitute->name }}</span>
                        </div>
                        <div class="mt-1">
                            @if ($pengajuan->substitute_accepted_at)
                                <x-status-badge warna="emerald"
                                                :label="'Bersedia · ' . $pengajuan->substitute_accepted_at->translatedFormat('d M H:i')" />
                            @elseif ($pengajuan->substitute_rejected_at)
                                <x-status-badge warna="red" label="Tidak bisa" />
                            @else
                                <x-status-badge warna="amber" label="Menunggu jawaban pengganti" />
                            @endif
                        </div>
                        @if ($pengajuan->substitute_note)
                            <p class="mt-1 text-xs text-slate-500">"{{ $pengajuan->substitute_note }}"</p>
                        @endif
                    @else
                        <span class="text-red-600">Belum ditunjuk — pengajuan tidak bisa disetujui.</span>
                    @endif
                </dd>
            </div>

            {{-- Kode lembur, supaya admin bisa membacakannya ke orangnya. --}}
            @if ($pengajuan->type->value === 'overtime' && $d?->secret_code)
                <div class="flex px-5 py-3">
                    <dt class="w-40 shrink-0 text-slate-500">Kode lembur</dt>
                    <dd>
                        <span class="rounded-lg bg-indigo-50 px-2.5 py-1 font-mono text-base font-bold tracking-[0.2em] text-indigo-900">
                            {{ $d->secret_code }}
                        </span>
                        <p class="mt-1 text-xs text-slate-500">
                            Bacakan ke {{ $pengajuan->employee?->name }}. Tanpa diaktifkan, lemburnya tidak dibayar.
                        </p>
                    </dd>
                </div>
            @endif

            @if ($pengajuan->decided_at)
                <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">Diputuskan</dt>
                    <dd>{{ $pengajuan->decider?->name }} — {{ $pengajuan->decided_at->translatedFormat('d M Y H:i') }}
                        @if ($pengajuan->decision_note)<div class="text-slate-500">{{ $pengajuan->decision_note }}</div>@endif
                    </dd></div>
            @endif
        </dl>

        @if ($pengajuan->status->value === 'pending_peer')
            <div class="border-t border-slate-100 bg-amber-50 px-5 py-4 text-sm text-amber-900">
                Menunggu {{ $pengajuan->substitute?->name ?? 'pengganti' }} menyatakan bersedia.
                Selama itu belum dijawab, pengajuan ini belum bisa Anda putuskan.
            </div>
        @endif

        @if ($pengajuan->status->value === 'pending_manager')
            <div class="flex flex-wrap gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4">
                <form method="POST" action="{{ route('manajer.pengajuan.approve', $pengajuan) }}">
                    @csrf
                    <button class="btn-setuju">
                        Setujui
                    </button>
                </form>

                <form method="POST" action="{{ route('manajer.pengajuan.reject', $pengajuan) }}" class="flex flex-1 gap-2">
                    @csrf
                    {{-- Alasan penolakan wajib: menolak tanpa penjelasan adalah
                         cara tercepat membuat orang berhenti memakai sistem. --}}
                    <input type="text" name="note" required minlength="5" placeholder="Alasan penolakan (wajib)"
                           class="kolom flex-1">
                    <button class="btn-tolak">
                        Tolak
                    </button>
                </form>
            </div>
        @endif
    </div>
</div>
@endsection
