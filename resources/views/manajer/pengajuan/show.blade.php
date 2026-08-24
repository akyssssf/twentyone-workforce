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
                    @if ($d?->isTukarLibur())
                        {{--
                            Tukar libur menyentuh DUA tanggal. Menampilkannya
                            seperti tukar shift biasa membuat yang menyetujui
                            tidak tahu ada tanggal kedua yang ikut berubah.
                        --}}
                        <div class="flex px-5 py-3"><dt class="w-40 shrink-0 text-slate-500">Bentuk</dt>
                            <dd class="font-medium">Tukar hari libur</dd></div>
                        <div class="flex px-5 py-3"><dt class="w-40 shrink-0 text-slate-500">Libur pengaju</dt>
                            <dd>{{ $d?->requesterAssignment?->work_date?->translatedFormat('D, d M Y') }} —
                                jadi masuk {{ $d?->partnerAssignment?->shift?->name }},
                                dan {{ $d?->partner?->name }} yang libur</dd></div>
                        <div class="flex px-5 py-3"><dt class="w-40 shrink-0 text-slate-500">Libur rekan</dt>
                            <dd>{{ $d?->partnerAssignment2?->work_date?->translatedFormat('D, d M Y') }} —
                                {{ $d?->partner?->name }} jadi masuk {{ $d?->requesterAssignment2?->shift?->name }},
                                dan pengaju yang libur</dd></div>
                    @else
                        <div class="flex px-5 py-3"><dt class="w-40 text-slate-500">Jadwal pengaju</dt>
                            <dd>{{ $d?->requesterAssignment?->work_date?->translatedFormat('d M Y') }} — {{ $d?->requesterAssignment?->shift?->name }}</dd></div>
                    @endif
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
                <p>
                    Menunggu {{ $pengajuan->substitute?->name ?? 'pengganti' }} menyatakan bersedia.
                    Selama itu belum dijawab, pengajuan ini belum bisa Anda putuskan.
                </p>

                {{-- Jalur alternatif: pengganti sering lebih gampang dihubungi
                     langsung lewat telepon/WA pribadi daripada disuruh buka
                     aplikasi. Admin yang sudah dapat kepastian lisan bisa
                     tandai di sini, tidak perlu menunggu pengganti login
                     sendiri. --}}
                @if ($pengajuan->substitute?->phone)
                    <a href="https://wa.me/{{ $pengajuan->substitute->phone }}?text={{ rawurlencode('Halo ' . $pengajuan->substitute->name . ', ' . $pengajuan->employee?->name . ' mengajukan ' . $pengajuan->type->label() . ' dan menunjuk Anda sebagai pengganti. Bersedia?') }}"
                       target="_blank" rel="noopener noreferrer"
                       class="btn mt-3 bg-[#25D366] text-white hover:bg-[#1eb455]">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2zm0 18.15a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.19 8.19 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.24-8.24a8.2 8.2 0 0 1 8.23 8.25c0 4.54-3.69 8.23-8.23 8.23zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.25-.64.8-.79.97-.14.16-.29.19-.54.06-.25-.12-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.01-.38.11-.51.11-.11.25-.29.37-.43.13-.15.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.13-.56-1.35-.77-1.84-.2-.49-.4-.42-.56-.43h-.47c-.17 0-.43.06-.66.31-.22.25-.86.85-.86 2.07s.89 2.4 1.01 2.56c.12.17 1.74 2.66 4.22 3.73.59.25 1.05.4 1.41.52.59.19 1.13.16 1.56.1.47-.07 1.47-.6 1.67-1.18.21-.58.21-1.08.15-1.18-.06-.11-.22-.17-.47-.29z"/>
                        </svg>
                        Hubungi {{ $pengajuan->substitute->name }} lewat WhatsApp
                    </a>
                @endif

                <form method="POST" action="{{ route('manajer.pengajuan.confirm-substitute', $pengajuan) }}"
                      class="mt-3 flex flex-wrap items-center gap-2">
                    @csrf
                    <input type="text" name="note" placeholder="Catatan (opsional) — mis. \"dikonfirmasi lewat telepon\""
                           class="kolom min-w-56 flex-1">
                    <button class="btn-netral"
                            onclick="return confirm('Pastikan {{ $pengajuan->substitute?->name ?? 'penggantinya' }} sudah benar-benar bilang bersedia sebelum menandai ini.')">
                        Tandai pengganti sudah setuju
                    </button>
                </form>
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
