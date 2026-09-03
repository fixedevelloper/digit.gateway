<?php

namespace Database\Factories;

use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Country>
 */
class CountryFactory extends Factory
{
    protected $model = Country::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->country(),
            'iso' => strtoupper(fake()->unique()->lexify('??')),
            'iso3' => strtoupper(fake()->unique()->lexify('???')),
            'currency' => 'XAF',
            'numcode' => fake()->unique()->numberBetween(100, 999),
            'phonecode' => fake()->numberBetween(1, 999),
            'status' => true,
            'flag' => null,
        ];
    }
}
