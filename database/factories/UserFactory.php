<?php

namespace Database\Factories;

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
            'name' => fake()->name(),
            'phone' => fake()->unique()->numerify('6########'),
            'password' => static::$password ??= Hash::make('password'),
            'transaction_pin' => Hash::make('1234'),
            'role' => 'customer',
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the user is a platform administrator.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
        ]);
    }

    /**
     * Indicate that the user is a B2B merchant account (intégrateur de la passerelle).
     */
    public function merchant(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'merchant',
            'email' => fake()->unique()->safeEmail(),
            'company_name' => fake()->company(),
            'environment' => 'sandbox',
            'status' => true,
        ]);
    }
}
