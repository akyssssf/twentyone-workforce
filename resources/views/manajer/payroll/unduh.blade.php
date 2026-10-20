@extends('layouts.app')
@section('title', 'Unduh Slip ' . $period->code)
@section('lebar', 'max-w-4xl')

@section('content')
@php
    $denganBonus = $slips->where('adaBonus', true);
    $totalDokumen = $slips->count() + $denganBonus->count();
@endphp

<div class="mb-5">
    <a href="{{ route('manajer.payroll.show', $period) }}" class="text-sm text-slate-500 hover:underline">&larr; Payroll {{ $period->code }}</a>
    <h1 class="mt-1 text-2xl font-semibold tracking-tight">Unduh semua slip</h1>
    <p class="mt-1 text-sm text-slate-500">
        Periode {{ $period->label() }} &middot; {{ $slips->count() }} slip gaji + {{ $denganBonus->count() }} slip bonus
        = {{ $totalDokumen }} berkas PDF dalam satu ZIP.
    </p>
</div>

@if ($period->status->value === 'generated')
    <div class="pemberitahuan mb-5 border-amber-200 bg-amber-50 text-amber-900">
        Periode ini masih <b>draf</b> (belum disetujui). Angka di PDF bisa berubah kalau payroll dihitung ulang.
    </div>
@endif

<div class="kartu mb-5 p-5">
    <div class="flex flex-wrap items-center gap-3">
        <button id="btn-zip" type="button" class="btn-utama">Unduh semua (ZIP)</button>
        <span id="status-zip" class="text-sm text-slate-500">Siap. Prosesnya sekitar {{ max(1, (int) ceil($totalDokumen / 3)) }} detik.</span>
    </div>
    <div class="mt-3 h-2 overflow-hidden rounded bg-slate-100"><div id="bar-zip" class="h-2 w-0 bg-slate-900 transition-all"></div></div>
    <p class="mt-3 text-xs text-slate-400">
        Isi ZIP: folder <b>Slip_Gaji</b> dan <b>Slip_Bonus</b>, tiap berkas bernama NAMA_DIVISI_SLIP_..._TANGGAL.pdf.
        Jangan tutup halaman ini selama prosesnya berjalan.
    </p>
</div>

<div class="kartu overflow-hidden">
    <table class="tabel">
        <thead><tr><th>Karyawan</th><th>Divisi</th><th class="text-right">THP</th><th class="text-right">Bonus</th><th></th></tr></thead>
        <tbody>
            @foreach ($slips as $i => $v)
                <tr>
                    <td class="font-medium">{{ $v['nama'] }}</td>
                    <td class="text-slate-500">{{ $v['divisi'] }}</td>
                    <td class="text-right tabular-nums">{{ number_format($v['thp'], 0, ',', '.') }}</td>
                    <td class="text-right tabular-nums {{ $v['adaBonus'] ? 'text-emerald-700' : 'text-slate-300' }}">{{ $v['adaBonus'] ? number_format($v['totalBonus'], 0, ',', '.') : '—' }}</td>
                    <td class="text-right whitespace-nowrap">
                        <button type="button" class="text-slate-700 hover:underline" data-satu="gaji:{{ $i }}">Gaji</button>
                        @if ($v['adaBonus'])
                            <button type="button" class="ml-3 text-amber-700 hover:underline" data-satu="bonus:{{ $i }}">Bonus</button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

{{-- Wadah render di luar layar, lebar tepat A4 (794 px). Dokumennya dibuat
     dari templat bergaya inline: html2canvas tidak mengerti warna oklch()
     milik Tailwind 4. --}}
<div style="position:fixed;left:-10000px;top:0;width:794px;" aria-hidden="true">
    <div id="wadah-slip" style="width:794px;background:#fff;"></div>
</div>

<template id="tpl-dokumen">
    @foreach ($slips as $i => $v)
        <div data-doc="gaji:{{ $i }}" data-nama="{{ $v['namaBerkasGaji'] }}">@include('slip.dokumen.gaji', ['v' => $v])</div>
        @if ($v['adaBonus'])
            <div data-doc="bonus:{{ $i }}" data-nama="{{ $v['namaBerkasBonus'] }}">@include('slip.dokumen.bonus', ['v' => $v])</div>
        @endif
    @endforeach
</template>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
(function () {
    const wadah = document.getElementById('wadah-slip');
    const dokumen = Array.from(document.getElementById('tpl-dokumen').content.querySelectorAll('[data-doc]'));
    const status = document.getElementById('status-zip');
    const bar = document.getElementById('bar-zip');
    const btnZip = document.getElementById('btn-zip');

    // A4 = 794 x 1123 px pada 96 dpi.
    const opsi = {
        margin: 0,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: {
            scale: 2, useCORS: true, scrollX: 0, scrollY: 0, x: 0, y: 0, width: 794, windowWidth: 794, backgroundColor: '#ffffff',
            // html2canvas membaca gaya <html> dan <body> juga, dan di situ
            // Tailwind 4 memakai warna oklch() yang tidak dimengertinya
            // ("unsupported color function oklch"). Pada SALINAN yang
            // ditangkap, kelas-kelasnya dibuang; halaman aslinya tidak berubah.
            // Lembar gaya Tailwind juga dibuang dari salinan: aturan dasarnya
            // (border-color, ring, dst.) memakai oklch() untuk SETIAP elemen,
            // termasuk yang bergaya inline. Dokumen slip tidak membutuhkannya.
            onclone: (doc) => {
                doc.querySelectorAll('link[rel="stylesheet"], style').forEach((n) => n.remove());
                [doc.documentElement, doc.body].forEach((n) => {
                    n.removeAttribute('class');
                    n.style.cssText = 'background:#ffffff;color:#111827;';
                });
            },
        },
        jsPDF: { unit: 'px', format: [794, 1123], orientation: 'portrait', hotfixes: ['px_scaling'] },
    };

    const tunggu = (ms) => new Promise((r) => setTimeout(r, ms));

    // html2canvas membaca warna <html> dan <body> halaman ASLI sebelum
    // mengkloning, dan di situ Tailwind 4 memakai oklch() yang tidak
    // dimengertinya. Selama pembuatan PDF, keduanya dipasangi warna hex lewat
    // gaya inline, lalu dikembalikan persis seperti semula.
    function netralkanHalaman() {
        const sasaran = [document.documentElement, document.body];
        const semula = sasaran.map((n) => n.getAttribute('style'));
        sasaran.forEach((n) => n.style.cssText = 'background-color:#ffffff;color:#111827;');
        return () => sasaran.forEach((n, i) => semula[i] === null ? n.removeAttribute('style') : n.setAttribute('style', semula[i]));
    }

    async function buatPdf(el) {
        const pulihkan = netralkanHalaman();
        try { return await buatPdfMentah(el); } finally { pulihkan(); }
    }

    // Satu dokumen -> Blob PDF. Dirender ke wadah dulu supaya gambar (logo)
    // sempat termuat sebelum ditangkap.
    async function buatPdfMentah(el) {
        wadah.innerHTML = '';
        const halaman = document.createElement('div');
        halaman.style.cssText = 'width:794px;height:1122px;padding:36px 46px;box-sizing:border-box;overflow:hidden;background:#fff;';
        halaman.innerHTML = el.innerHTML;
        wadah.appendChild(halaman);

        await Promise.all(Array.from(halaman.querySelectorAll('img')).map((img) =>
            img.complete ? Promise.resolve() : new Promise((r) => { img.onload = img.onerror = r; })));
        await tunggu(80);

        return await html2pdf().set(opsi).from(wadah).outputPdf('blob');
    }

    function simpan(blob, nama) {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = nama;
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(url), 3000);
    }

    let sibuk = false;
    function kunci(nilai) { sibuk = nilai; btnZip.disabled = nilai; btnZip.classList.toggle('opacity-60', nilai); }

    document.querySelectorAll('[data-satu]').forEach((tombol) => tombol.addEventListener('click', async () => {
        if (sibuk) return;
        const el = dokumen.find((d) => d.dataset.doc === tombol.dataset.satu);
        kunci(true); status.textContent = 'Menyiapkan ' + el.dataset.nama + '…';
        try { simpan(await buatPdf(el), el.dataset.nama + '.pdf'); status.textContent = 'Selesai.'; }
        catch (e) { console.error(e); status.textContent = 'Gagal membuat PDF. Coba Chrome.'; }
        finally { kunci(false); wadah.innerHTML = ''; }
    }));

    btnZip.addEventListener('click', async () => {
        if (sibuk) return;
        kunci(true);
        try {
            const zip = new JSZip();
            for (let i = 0; i < dokumen.length; i++) {
                const el = dokumen[i];
                status.textContent = 'Membuat ' + (i + 1) + '/' + dokumen.length + ': ' + el.dataset.nama;
                bar.style.width = ((i / dokumen.length) * 100) + '%';
                const folder = el.dataset.doc.startsWith('bonus') ? 'Slip_Bonus/' : 'Slip_Gaji/';
                zip.file(folder + el.dataset.nama + '.pdf', await buatPdf(el));
            }
            status.textContent = 'Membungkus ZIP…';
            const blob = await zip.generateAsync({ type: 'blob', compression: 'DEFLATE' });
            bar.style.width = '100%';
            simpan(blob, 'Slip_21KAFE_{{ $period->code }}.zip');
            status.textContent = 'Selesai: ' + dokumen.length + ' berkas.';
        } catch (e) {
            console.error(e); status.textContent = 'Gagal membuat ZIP. Coba Chrome.';
        } finally { kunci(false); wadah.innerHTML = ''; }
    });

    window.__unduh = { dokumen, buatPdf }; // untuk pemeriksaan
})();
</script>
@endsection
