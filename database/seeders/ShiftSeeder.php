<?php

namespace Database\Seeders;

use App\Models\Shift;
use Illuminate\Database\Seeder;

class ShiftSeeder extends Seeder
{
    /**
     * start_time adalah batas on time, bukan jam buka kafe.
     */
    public function run(): void
    {
        $shifts = [
            ['name' => 'Shift 1', 'start_time' => '09:00:00', 'end_time' => '17:00:00'],
            ['name' => 'Shift 2', 'start_time' => '17:00:00', 'end_time' => '01:00:00'],
        ];

        foreach ($shifts as $shift) {
            Shift::updateOrCreate(
                ['name' => $shift['name']],
                $shift + ['is_active' => true],
            );
        }
    }
}
