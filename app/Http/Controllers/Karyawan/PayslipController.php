<?php

namespace App\Http\Controllers\Karyawan;

use App\Http\Controllers\Controller;
use App\Models\Payslip;
use Illuminate\Http\Request;

class PayslipController extends Controller
{
    public function index(Request $request)
    {
        return view('karyawan.slip.index', [
            'payslips' => Payslip::query()
                ->with('run.period')
                ->where('employee_id', $request->user()->employee_id)
                ->published()
                ->latest('id')
                ->get(),
        ]);
    }

    public function show(Payslip $payslip, Request $request)
    {
        // Otorisasi PER BARIS. Menyembunyikan tautan saja tidak cukup —
        // /karyawan/slip/123 harus benar-benar ditolak untuk slip orang lain.
        abort_unless($payslip->employee_id === $request->user()->employee_id, 403);

        // Slip yang belum disetujui belum boleh dilihat: angkanya masih bisa
        // berubah, dan slip yang berubah-ubah lebih merusak kepercayaan
        // daripada slip yang terlambat.
        abort_unless($payslip->status === 'published', 403, 'Slip gaji ini belum diterbitkan.');

        return view('slip.show', [
            'payslip' => $payslip->load(['items', 'employee', 'run.period']),
            'kembali' => route('karyawan.slip.index'),
        ]);
    }
}
