<?php

namespace Database\Factories;

use App\Models\Country;
use App\Models\Operator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Operator>
 */
class OperatorFactory extends Factory
{
    protected $model = Operator::class;

    public function definition(): array
    {
        return [
            'country_id' => Country::factory(),
            'name' => fake()->company(),
            'code' => strtoupper(fake()->unique()->bothify('OP_????')),
            'status' => true,
            'prefix_regex' => null,
            'phone_length' => 9,
            'fixed_fee' => 50.00,
            'percent_fee' => 0.01,
            'min_amount' => 100.00,
            'max_amount' => 500000.00,
        ];
    }
}
