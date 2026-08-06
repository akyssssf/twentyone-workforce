<?php

namespace App\Http\Controllers\Manajer;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditLog::query()->with('actor')->latest('id');

        // Bawaannya hanya tindakan manusia. Tanpa saringan ini, halaman penuh
        // perubahan otomatis cron tiap 15 menit dan approval yang penting
        // tenggelam di antaranya.
        if (! $request->boolean('sistem')) {
            $query->byHuman();
        }

        if ($aksi = $request->query('aksi')) {
            $query->where('action', 'like', "%{$aksi}%");
        }

        return view('manajer.audit.index', [
            'logs' => $query->paginate(50)->withQueryString(),
            'aksiTersedia' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
