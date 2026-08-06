<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Shift>
 */
class ShiftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'pagi',
            'name' => 'Shift 1',
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'crosses_midnight' => false,
            'break_minutes' => 60,
            'is_break_paid' => true,
            'window_before_hours' => 4,
            'window_after_hours' => 4,
            'is_active' => true,
        ];
    }

    /**
     * Shift malam kafe: 17:00 sampai 01:00, melewati tengah malam.
     */
    public function malam(): static
    {
        return $this->state(fn () => [
            'code' => 'malam',
            'name' => 'Shift 2',
            'start_time' => '17:00:00',
            'end_time' => '01:00:00',
            'crosses_midnight' => true,
        ]);
    }
}
