<?php

namespace App\Http\Controllers;

use App\Services\Attendance\MonthlyReport;
use App\Services\Attendance\MonthlyReportExcel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        $report = $this->reportDari($request);

        return view('laporan.bulanan', [
            'report' => $report,
            'ringkasan' => $report->ringkasan(),
            'total' => $report->total(),
            'periode' => $report->periodeAwal(),
        ]);
    }

    public function excel(Request $request): BinaryFileResponse
    {
        $report = $this->reportDari($request);

        // Ditulis ke berkas sementara lalu dikirim dengan deleteFileAfterSend,
        // bukan di-stream langsung. Kalau di-stream, kesalahan apa pun di
        // tengah proses akan menghasilkan berkas xlsx rusak yang tidak bisa
        // dibuka, dan penyebabnya sulit ditelusuri.
        $path = tempnam(sys_get_temp_dir(), 'rekap').'.xlsx';

        (new MonthlyReportExcel($report))->simpan($path);

        return response()
            ->download($path, $report->namaBerkas())
            ->deleteFileAfterSend();
    }

    protected function reportDari(Request $request): MonthlyReport
    {
        $timezone = config('attendance.timezone', 'Asia/Jakarta');
        $sekarang = Carbon::now($timezone);

        // Input bulan dari <input type="month"> berbentuk "2026-08".
        $bulan = $request->query('bulan');

        if (is_string($bulan) && preg_match('/^(\d{4})-(\d{2})$/', $bulan, $cocok)) {
            $tahun = (int) $cocok[1];
            $nomorBulan = (int) $cocok[2];

            // Bulan ngawur di URL jangan bikin halaman error.
            if ($nomorBulan >= 1 && $nomorBulan <= 12 && $tahun >= 2000 && $tahun <= 2100) {
                return MonthlyReport::for($tahun, $nomorBulan);
            }
        }

        return MonthlyReport::for($sekarang->year, $sekarang->month);
    }
}
