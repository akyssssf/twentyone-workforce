<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Branch>
 */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'pusat',
            'name' => 'Kafe Pusat',
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
        ];
    }
}
