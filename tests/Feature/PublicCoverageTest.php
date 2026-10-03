<?php

namespace Tests\Feature;

use App\Enums\CountryServiceStatus;
use App\Enums\TransferService;
use App\Models\Country;
use App\Models\CountryService;
use App\Models\Operator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublicCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function country(string $name, string $iso): Country
    {
        return Country::factory()->create(['name' => $name, 'iso' => $iso, 'currency' => 'XAF', 'status' => true]);
    }

    public function test_it_lists_covered_countries_without_authentication_and_without_internal_data(): void
    {
        $cm = $this->country('Cameroun', 'CM');
        Operator::factory()->create(['country_id' => $cm->id, 'name' => 'MTN']);
        Operator::factory()->create(['country_id' => $cm->id, 'name' => 'Orange']);
        CountryService::factory()->create(['country_id' => $cm->id, 'service' => TransferService::BankTransfer, 'status' => CountryServiceStatus::Manual, 'daily_limit' => 1000]);

        $response = $this->getJson('/api/public/coverage')->assertOk()->assertJsonCount(1, 'data');

        $country = $response->json('data.0');
        $this->assertSame(['MOBILE_MONEY', 'BANK_TRANSFER'], $country['services']);
        $this->assertEqualsCanonicalizing(['MTN', 'Orange'], $country['operators']);
        $this->assertEqualsCanonicalizing(['name', 'iso', 'currency', 'flag_url', 'services', 'operators'], array_keys($country));
    }

    public function test_it_applies_the_admin_configuration(): void
    {
        $bankOnly = $this->country('Senegal', 'SN');
        CountryService::factory()->create(['country_id' => $bankOnly->id, 'service' => TransferService::BankTransfer, 'status' => CountryServiceStatus::Active]);

        $mmDisabled = $this->country('Gabon', 'GA');
        Operator::factory()->create(['country_id' => $mmDisabled->id]);
        CountryService::factory()->create(['country_id' => $mmDisabled->id, 'status' => CountryServiceStatus::Inactive]);

        $inactiveCountry = Country::factory()->create(['name' => 'Chad', 'status' => false]);
        Operator::factory()->create(['country_id' => $inactiveCountry->id]);

        $this->country('Mali', 'ML'); // ni opérateur ni service

        $data = $this->getJson('/api/public/coverage')->assertOk()->json('data');

        $this->assertCount(1, $data); // Gabon (MM coupé), Chad (inactif) et Mali (rien) exclus
        $this->assertSame('SN', $data[0]['iso']);
        $this->assertSame(['BANK_TRANSFER'], $data[0]['services']);
        $this->assertSame([], $data[0]['operators']);
    }

    public function test_the_result_is_cached(): void
    {
        Cache::flush();
        $this->country('Senegal', 'SN');
        $sn = Country::where('iso', 'SN')->first();
        CountryService::factory()->create(['country_id' => $sn->id, 'service' => TransferService::BankTransfer]);

        $this->getJson('/api/public/coverage')->assertJsonCount(1, 'data');
        $sn->update(['status' => false]);

        $this->getJson('/api/public/coverage')->assertJsonCount(1, 'data'); // encore en cache
        Cache::forget('public-coverage');
        $this->getJson('/api/public/coverage')->assertJsonCount(0, 'data');
    }
}
