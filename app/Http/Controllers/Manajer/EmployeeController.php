<?php

namespace App\Http\Controllers\Manajer;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\Employee;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index()
    {
        return view('manajer.karyawan.index', [
            'employees' => Employee::query()
                ->with(['divisions', 'defaultShift', 'devices'])
                ->orderBy('name')
                ->get(),
            'divisions' => Division::query()->active()->orderBy('sort_order')->get(),
        ]);
    }

    public function show(Employee $employee)
    {
        return view('manajer.karyawan.show', [
            'employee' => $employee->load(['divisions', 'devices', 'salaries.component', 'leaveBalances.leaveType']),
            'divisions' => Division::query()->active()->orderBy('sort_order')->get(),
        ]);
    }

    public function syncDivisions(Employee $employee, Request $request)
    {
        $data = $request->validate([
            'divisions' => ['required', 'array', 'min:1'],
            'divisions.*' => ['exists:divisions,id'],
            'primary' => ['required', 'exists:divisions,id'],
        ]);

        $payload = [];

        foreach ($data['divisions'] as $id) {
            $payload[$id] = [
                'is_primary' => (int) $id === (int) $data['primary'],
                'competency_level' => (int) $id === (int) $data['primary'] ? 3 : 1,
            ];
        }

        $employee->divisions()->sync($payload);

        AuditLogger::record('employee.divisions_changed', $employee, [], $payload);

        return back()->with('status', 'Divisi diperbarui.');
    }
}
