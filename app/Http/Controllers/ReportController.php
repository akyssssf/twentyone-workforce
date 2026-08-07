<?php

namespace App\Http\Controllers;

use App\Services\Attendance\MonthlyReport;
use App\Services\Attendance\MonthlyReportExcel;
use App\Support\DateInput;
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
            'tampilan' => $report->granularitas,
            'waTeks' => $report->teksWhatsApp(),
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
        $tampilan = $request->query('tampilan', 'bulanan');

        if ($tampilan === 'harian') {
            $tanggal = DateInput::parse($request->query('tanggal'), $timezone) ?? $sekarang;

            return MonthlyReport::forDay($tanggal);
        }

        if ($tampilan === 'mingguan') {
            // Input dari <input type="week"> berbentuk "2026-W32".
            $minggu = $request->query('minggu');

            if (is_string($minggu) && preg_match('/^(\d{4})-W(\d{2})$/', $minggu, $cocok)) {
                $tahun = (int) $cocok[1];
                $nomorMinggu = (int) $cocok[2];

                if ($nomorMinggu >= 1 && $nomorMinggu <= 53 && $tahun >= 2000 && $tahun <= 2100) {
                    $tanggal = Carbon::now($timezone)->setISODate($tahun, $nomorMinggu);

                    return MonthlyReport::forWeek($tanggal);
                }
            }

            return MonthlyReport::forWeek($sekarang);
        }

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
