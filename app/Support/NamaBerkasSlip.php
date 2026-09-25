<?php

namespace App\Support;

use App\Models\Payslip;
use Illuminate\Support\Str;

/**
 * Nama berkas slip saat karyawan menekan "Simpan sebagai PDF".
 *
 * Peramban memakai <title> halaman sebagai nama berkas bawaannya — tidak ada
 * cara lain menamainya dari sisi kita. Jadi judul halaman cetak sengaja
 * dijadikan nama berkas yang diinginkan: NAMA_DIVISI_SLIP_BONUS_21-09-2026.
 *
 * Data diambil dari snapshot slip, bukan dari tabel karyawan, supaya berkas
 * slip lama tetap bernama divisi yang berlaku saat itu.
 */
class NamaBerkasSlip
{
    public static function untuk(Payslip $payslip, string $jenis): string
    {
        $snapshot = $payslip->employee_snapshot ?? [];

        $bagian = [
            static::bersihkan($snapshot['name'] ?? $payslip->employee?->name ?? 'KARYAWAN'),
            static::bersihkan($snapshot['division'] ?? ''),
            $jenis,
            $payslip->run?->period?->pay_date?->format('d-m-Y') ?? '',
        ];

        return implode('_', array_filter($bagian, fn ($b) => $b !== ''));
    }

    protected static function bersihkan(string $teks): string
    {
        // Spasi jadi garis bawah, tanda baca dibuang: "Admin Dept." yang
        // mengandung titik akan dibaca peramban sebagai akhiran berkas.
        return Str::upper(Str::of($teks)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', ' ')->trim()->replace(' ', '_'));
    }
}
