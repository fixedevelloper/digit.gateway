<?php

namespace Database\Factories;

use App\Models\Agency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agency>
 */
class AgencyFactory extends Factory
{
    protected $model = Agency::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('AG-###??')),
            'name' => fake()->company(),
            'city' => fake()->city(),
            'address' => fake()->address(),
            'status' => 'active',
        ];
    }
}
