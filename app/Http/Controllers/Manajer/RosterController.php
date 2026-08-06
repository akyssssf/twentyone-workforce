<?php

namespace App\Http\Controllers\Manajer;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Roster;
use App\Models\Shift;
use App\Services\Roster\RosterService;
use App\Services\Roster\RosterValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class RosterController extends Controller
{
    public function __construct(
        protected RosterService $service,
        protected RosterValidator $validator,
    ) {}

    public function index()
    {
        return view('manajer.roster.index', [
            'rosters' => Roster::query()->orderByDesc('period_year')->orderByDesc('period_month')->get(),

            // Brief menyebut roster bulan depan dibuat sekitar tanggal 20-25.
            // Tombolnya disodorkan duluan supaya manager tidak perlu mengingat.
            'bulanDepan' => now()->addMonthNoOverflow(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'period_year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $roster = $this->service->findOrCreate((int) $data['period_year'], (int) $data['period_month']);

        return redirect()->route('manajer.roster.show', $roster);
    }

    public function show(Roster $roster)
    {
        $grid = $this->service->grid($roster);
        $issues = $this->validator->validate($roster);

        return view('manajer.roster.show', [
            'roster' => $roster,
            'shifts' => Shift::query()->where('is_active', true)->get(),
            'issues' => $issues,
            ...$grid,
        ]);
    }

    public function generate(Roster $roster, Request $request)
    {
        $hasil = $this->service->generate($roster, $request->boolean('overwrite'));

        return back()->with('status', "Jadwal awal dibuat: {$hasil['created']} baris, {$hasil['skipped']} dilewati.");
    }

    public function publish(Roster $roster)
    {
        $hasil = $this->service->publish($roster);

        if (! $hasil['published']) {
            return back()->withErrors([
                'roster' => 'Masih ada ' . $hasil['issues']['errors']->count() . ' masalah yang memblokir penerbitan.',
            ]);
        }

        $peringatan = $hasil['issues']['warnings']->count();

        return back()->with('status', $peringatan > 0
            ? "Roster terbit dengan {$peringatan} peringatan yang perlu Anda ketahui."
            : 'Roster terbit.');
    }

    public function assign(Roster $roster, Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'work_date' => ['required', 'date'],
            'shift_id' => ['nullable', 'exists:shifts,id'],
            'division_id' => ['nullable', 'exists:divisions,id'],
        ]);

        $this->service->assign(
            $roster,
            Employee::findOrFail($data['employee_id']),
            Carbon::parse($data['work_date']),
            $data['shift_id'] ? (int) $data['shift_id'] : null,
            $data['division_id'] ? (int) $data['division_id'] : null,
        );

        return back()->with('status', 'Jadwal diperbarui.');
    }
}
