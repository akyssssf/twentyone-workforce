<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Support\DateInput;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Daftar semua scan yang pernah masuk, mirip Real-Time Event di dashboard
 * mesin.
 *
 * Satu perbedaan penting yang tidak bisa dihindari: halaman ini hanya memuat
 * scan yang BERHASIL. Fingerspot tidak mengirimkan percobaan yang gagal
 * ("Authentication via Face Failed") lewat API mana pun, jadi kegagalan hanya
 * bisa dilihat di dashboard mesin di jaringan lokal.
 */
class ScanActivityController extends Controller
{
    public function __invoke(Request $request): View
    {
        $timezone = config('attendance.timezone', 'Asia/Jakarta');

        $dari = DateInput::parse($request->query('dari'), $timezone);
        $sampai = DateInput::parse($request->query('sampai'), $timezone);
        $pin = trim((string) $request->query('pin', ''));
        $sumber = $request->query('sumber');

        $query = AttendanceLog::query()
            ->when($dari, fn ($q) => $q->where('scanned_at', '>=', $dari->copy()->startOfDay()))
            ->when($sampai, fn ($q) => $q->where('scanned_at', '<=', $sampai->copy()->endOfDay()))
            ->when($pin !== '', fn ($q) => $q->where('pin', $pin))
            ->when(in_array($sumber, ['webhook', 'sync'], true), fn ($q) => $q->where('source', $sumber))
            ->orderByDesc('scanned_at');

        $scan = $query->paginate(50)->withQueryString();

        $namaPerPin = Employee::pluck('name', 'pin_device');

        return view('aktivitas.index', [
            'scan' => $scan,
            'namaPerPin' => $namaPerPin,
            'filter' => [
                'dari' => $dari?->toDateString(),
                'sampai' => $sampai?->toDateString(),
                'pin' => $pin,
                'sumber' => $sumber,
            ],
            'ringkasan' => [
                'total' => AttendanceLog::count(),
                'webhook' => AttendanceLog::where('source', 'webhook')->count(),
                'sync' => AttendanceLog::where('source', 'sync')->count(),
                'terakhir' => AttendanceLog::max('scanned_at'),
                'asing' => AttendanceLog::query()
                    ->when($namaPerPin->isNotEmpty(), fn ($q) => $q->whereNotIn('pin', $namaPerPin->keys()->all()))
                    ->distinct()->pluck('pin'),
            ],
        ]);
    }

}
