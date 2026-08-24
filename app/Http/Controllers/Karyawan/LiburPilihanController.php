<?php

namespace App\Http\Controllers\Karyawan;

use App\Http\Controllers\Controller;
use App\Services\Roster\LiburPilihanService;
use App\Support\DateInput;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Halaman karyawan memilih sendiri hari liburnya (Logistik).
 *
 * Pilihannya berlaku LANGSUNG tanpa persetujuan manajer, jadi ada satu langkah
 * konfirmasi di tengah: jatahnya cuma dua hari sebulan dan tidak bisa
 * dibatalkan sendiri, sehingga satu salah klik berarti satu hari libur hilang.
 * Layar konfirmasinya menyebut tanggalnya dan sisa jatah setelahnya, supaya
 * yang disetujui adalah sesuatu yang sudah terbaca.
 *
 * Semua aturannya ditegakkan di LiburPilihanService, bukan di sini — controller
 * cuma menerjemahkan pesannya ke layar. Tombol yang disembunyikan tidak
 * menghentikan siapa pun yang mengirim form-nya langsung.
 */
class LiburPilihanController extends Controller
{
    public function __construct(protected LiburPilihanService $service) {}

    public function index(Request $request)
    {
        $employee = $request->user()->employee;

        abort_unless($this->service->berlakuUntuk($employee), 403);

        return view('karyawan.libur.index', $this->data($employee));
    }

    public function store(Request $request)
    {
        $employee = $request->user()->employee;

        abort_unless($this->service->berlakuUntuk($employee), 403);

        $data = $request->validate([
            'tanggal' => ['required', 'date'],
            'konfirmasi' => ['nullable'],
        ]);

        $tanggal = DateInput::parse($data['tanggal']);

        if ($tanggal === null) {
            return back()->withErrors(['tanggal' => 'Tanggal tidak sah.']);
        }

        // Langkah pertama cuma menampilkan ulang halaman dengan panel
        // konfirmasi. Tidak ada yang tersimpan sampai tombol kedua ditekan.
        if (! $request->boolean('konfirmasi')) {
            // array_merge, BUKAN operator "+": pada array, "+" mempertahankan
            // nilai sisi KIRI untuk kunci yang sama, jadi 'konfirmasi' => null
            // bawaan data() akan menang dan panel konfirmasinya tidak pernah
            // muncul — form-nya cuma tampil ulang seperti tidak terjadi apa-apa.
            return view('karyawan.libur.index', array_merge($this->data($employee), [
                'konfirmasi' => $tanggal,

                // Jatah dihitung per bulan TANGGAL YANG DIPILIH, bukan bulan
                // berjalan — daftar kandidat mencakup 60 hari ke depan, jadi
                // pilihannya bisa jatuh di bulan berikutnya. Menampilkan sisa
                // bulan ini di situ akan menyebut angka yang tidak berlaku.
                'sisaPilihan' => $this->service->sisa($employee, $tanggal),
            ]));
        }

        try {
            $this->service->pilih($employee, $tanggal);
        } catch (RuntimeException $e) {
            return back()->withErrors(['tanggal' => $e->getMessage()]);
        }

        return redirect()
            ->route('karyawan.libur.index')
            ->with('status', 'Libur '.$tanggal->translatedFormat('l, d F Y').' sudah tercatat.');
    }

    /** @return array<string, mixed> */
    protected function data($employee): array
    {
        $bulan = today();

        return [
            'employee' => $employee,
            'bulan' => $bulan,
            'jatah' => $this->service->jatah(),
            'terpakai' => $this->service->terpakai($employee, $bulan),
            'sisa' => $this->service->sisa($employee, $bulan),
            'kandidat' => $this->service->kandidat($employee),
            'konfirmasi' => null,
            'sisaPilihan' => null,
        ];
    }
}
