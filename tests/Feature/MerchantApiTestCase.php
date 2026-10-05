<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Country;
use App\Models\Operator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Socle des tests de l'API marchande (/api/v1/gateway/*) : un pays (Cameroun) avec un opérateur
 * MTN_CM (100 à 100 000 XAF, 50 fixes + 1 %), des marchands sandbox / production et leurs clés.
 */
abstract class MerchantApiTestCase extends TestCase
{
    use RefreshDatabase;

    public const ALL_SCOPES = ['transfer.write', 'bank_transfer.write', 'withdrawal.write', 'deposit.write', 'wallet.read', 'transactions.read', 'countries.read'];

    protected Country $country;

    protected Operator $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->country = Country::factory()->create(['name' => 'Cameroon', 'iso' => 'CM', 'status' => true, 'currency' => 'XAF']);
        $this->operator = Operator::factory()->create([
            'country_id' => $this->country->id,
            'code' => 'MTN_CM',
            'name' => 'MTN Cameroun',
            'currency' => 'XAF',
            'status' => true,
            'fixed_fee' => 50,
            'percent_fee' => 0.01,
            'min_amount' => 100,
            'max_amount' => 100000,
        ]);
    }

    protected function merchant(string $environment = 'production', float $balance = 100000, float $sandbox = 100000): User
    {
        $merchant = User::factory()->merchant()->create(['environment' => $environment]);
        $merchant->wallet()->update(['balance' => $balance, 'sandbox_balance' => $sandbox]);

        return $merchant->fresh();
    }

    /** @return array{0: ApiKey, 1: string} */
    protected function apiKey(User $merchant, string $environment = 'production', array $scopes = self::ALL_SCOPES): array
    {
        ['model' => $model, 'plainTextKey' => $plain] = ApiKey::generateFor($merchant, 'test', $environment, $scopes);

        return [$model, $plain];
    }

    protected function headers(string $plainKey, ?string $idempotencyKey = null): array
    {
        return array_filter(['Authorization' => "Bearer {$plainKey}", 'Idempotency-Key' => $idempotencyKey]);
    }

    protected function transferBody(array $overrides = []): array
    {
        return array_merge(['country' => 'CM', 'carrier' => 'MTN_CM', 'number' => '677000001', 'amount' => 1000], $overrides);
    }
}
