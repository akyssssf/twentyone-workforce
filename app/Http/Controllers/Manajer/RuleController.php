<?php

namespace App\Http\Controllers\Manajer;

use App\Http\Controllers\Controller;
use App\Models\RuleSet;
use App\Services\Audit\AuditLogger;
use App\Support\Settings;
use Illuminate\Http\Request;

/**
 * Semua aturan yang menyentuh uang bisa diubah dari sini, tanpa deploy.
 *
 * Mengubah tarif TIDAK mengedit baris lama: ia menutup rule_set yang berlaku
 * dan membuat yang baru mulai hari ini. Slip gaji yang sudah terbit tetap
 * memakai tarif yang berlaku saat itu.
 */
class RuleController extends Controller
{
    public function index()
    {
        return view('manajer.aturan.index', [
            'ruleSets' => RuleSet::query()
                ->with('tiers')
                ->where('is_active', true)
                ->whereNull('effective_to')
                ->orderBy('type')
                ->get(),
            'settings' => Settings::all(),
        ]);
    }

    public function updateTiers(RuleSet $ruleSet, Request $request)
    {
        $data = $request->validate([
            'tiers' => ['required', 'array', 'min:1'],
            'tiers.*.id' => ['required', 'exists:rule_tiers,id'],
            'tiers.*.value' => ['required', 'numeric', 'min:0'],
        ]);

        $sebelum = $ruleSet->tiers->pluck('value', 'id')->all();

        foreach ($data['tiers'] as $row) {
            $ruleSet->tiers()->where('id', $row['id'])->update(['value' => $row['value']]);
        }

        AuditLogger::record('rule.updated', $ruleSet, $sebelum, collect($data['tiers'])->pluck('value', 'id')->all());

        return back()->with('status', 'Aturan diperbarui. Perubahan berlaku untuk perhitungan berikutnya.');
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
        ]);

        foreach ($data['settings'] as $key => $value) {
            // Nilai dari form selalu string. Dikembalikan ke tipe aslinya di
            // sini supaya pembaca setelan tidak perlu ingat mengonversi.
            $casted = match (true) {
                in_array($value, ['true', 'false'], true) => $value === 'true',
                is_numeric($value) => (int) $value,
                default => $value,
            };

            Settings::put($key, $casted);
        }

        AuditLogger::record('settings.updated', null, [], $data['settings']);

        return back()->with('status', 'Setelan disimpan.');
    }
}
