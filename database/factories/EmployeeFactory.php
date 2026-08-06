<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\EmployeeDevice;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Employee>
 */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::query()->value('id') ?? Branch::factory(),
            'employee_no' => 'EMP-'.$this->faker->unique()->numerify('####'),
            'pin_device' => (string) $this->faker->unique()->numberBetween(1, 9999),
            'name' => $this->faker->name(),
            'phone' => '0812'.$this->faker->numerify('########'),

            // Shift PREFERENSI, bukan jadwal. Jadwal sesungguhnya ada di
            // roster_assignments.
            'default_shift_id' => Shift::factory(),

            'employment_status' => 'active',
            'is_active' => true,
            'joined_at' => null,
        ];
    }

    /**
     * Pemetaan PIN dibuat otomatis setelah karyawan tersimpan.
     *
     * Tanpa ini, scan yang masuk tidak akan pernah tercocokkan ke siapa pun —
     * pencocokan memakai employee_devices, bukan employees.pin_device.
     */
    public function configure(): static
    {
        return $this->afterCreating(function ($employee) {
            EmployeeDevice::firstOrCreate(
                ['pin' => $employee->pin_device, 'valid_to' => null],
                [
                    'employee_id' => $employee->id,
                    'cloud_id' => config('fingerspot.cloud_id') ?: 'default',
                    'valid_from' => ($employee->joined_at ?? now()->subYear())->toDateString(),
                ],
            );
        });
    }

    public function nonaktif(): static
    {
        return $this->state(fn () => ['is_active' => false, 'employment_status' => 'resigned']);
    }

    public function tanpaShift(): static
    {
        return $this->state(fn () => ['default_shift_id' => null]);
    }
}
