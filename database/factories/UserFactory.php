<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Nama panggilan untuk login. Huruf kecil dan angka saja, seperti
            // yang dipakai di kafe.
            'username' => fake()->unique()->userName(),

            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::Admin,
            'is_active' => true,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin]);
    }

    public function configure(): static
    {
        // faker userName() bisa memuat titik dan garis bawah; dibersihkan
        // supaya sesuai aturan nama panggilan yang berlaku di aplikasi.
        return $this->afterMaking(function (User $user) {
            $user->username = preg_replace('/[^a-z0-9]/', '', mb_strtolower($user->username)) ?: 'user';
        });
    }

    public function nonaktif(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
