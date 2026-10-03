<?php

namespace Database\Factories;

use App\Enums\CountryServiceStatus;
use App\Enums\TransferService;
use App\Models\Country;
use App\Models\CountryService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CountryService>
 */
class CountryServiceFactory extends Factory
{
    protected $model = CountryService::class;

    public function definition(): array
    {
        return [
            'country_id' => Country::factory(),
            'service' => TransferService::MobileMoney,
            'status' => CountryServiceStatus::Active,
            'provider_id' => null,
        ];
    }
}
