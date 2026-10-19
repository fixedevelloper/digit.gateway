<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\MerchantPricing;
use App\Models\Operator;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/** Frais et taux propres à chaque marchand, par pays et opérateur (le taux global ne concerne que les clients). */
class MerchantPricingTest extends MerchantApiTestCase
{
    private function usdOperator(): Operator
    {
        return Operator::factory()->create([
            'country_id' => $this->country->id, 'code' => 'USD_OP', 'currency' => 'USD', 'status' => true,
            'fixed_fee' => 0.5, 'percent_fee' => 0.01, 'min_amount' => 1, 'max_amount' => 1000,
        ]);
    }

    private function transfer(string $plain, array $overrides = [], string $key = 'k1')
    {
        return $this->postJson('/api/v1/gateway/transfers', $this->transferBody($overrides), $this->headers($plain, $key));
    }

    public function test_a_merchant_without_pricing_is_refused_and_nothing_is_debited(): void
    {
        Queue::fake();
        $merchant = $this->merchant();
        MerchantPricing::where('merchant_id', $merchant->id)->delete();
        [, $plain] = $this->apiKey($merchant);

        $this->transfer($plain)->assertStatus(422)->assertJsonPath('error_code', 'PRICING_NOT_CONFIGURED');

        $this->assertSame(0, Transaction::count());
        $this->assertSame(100000.0, (float) $merchant->wallet->fresh()->balance);
    }

    public function test_an_inactive_pricing_is_refused(): void
    {
        $merchant = $this->merchant();
        MerchantPricing::where('merchant_id', $merchant->id)->update(['active' => false]);
        [, $plain] = $this->apiKey($merchant);

        $this->transfer($plain)->assertStatus(422)->assertJsonPath('error_code', 'PRICING_NOT_CONFIGURED');
    }

    public function test_each_merchant_pays_its_own_fees_not_the_operators(): void
    {
        Queue::fake();
        $a = $this->merchant();
        $b = $this->merchant();
        $this->priceFor($a, $this->operator, ['fixed_fee' => 10, 'percent_fee' => 0.02]);
        $this->priceFor($b, $this->operator, ['fixed_fee' => 0, 'percent_fee' => 0.005]);

        // Opérateur : 50 + 1 % = 60 pour 1000. Marchand A : 10 + 2 % = 30. Marchand B : 0,5 % = 5.
        $this->transfer($this->apiKey($a)[1])->assertOk()->assertJsonPath('data.fee', 30);
        $this->transfer($this->apiKey($b)[1], [], 'k2')->assertOk()->assertJsonPath('data.fee', 5);

        $this->assertSame(98970.0, (float) $a->wallet->fresh()->balance);
        $this->assertSame(98995.0, (float) $b->wallet->fresh()->balance);
    }

    public function test_a_merchant_quote_uses_its_own_rate_not_the_global_one(): void
    {
        Queue::fake();
        $usd = $this->usdOperator();
        ExchangeRate::create(['base_currency' => 'USD', 'quote_currency' => 'XAF', 'rate' => 600]);
        $merchant = $this->merchant();
        $this->priceFor($merchant, $usd, ['fixed_fee' => 0, 'percent_fee' => 0, 'rate' => 500]);
        [, $plain] = $this->apiKey($merchant);

        $this->postJson('/api/v1/gateway/quotes', [
            'type' => 'transfer', 'country' => 'CM', 'carrier' => 'USD_OP', 'amount' => 10000,
        ], $this->headers($plain))->assertCreated()
            ->assertJsonPath('data.rate', 500)
            ->assertJsonPath('data.converted_amount', 20)
            ->assertJsonPath('data.fee', 0);
    }

    public function test_a_cross_currency_pricing_without_a_rate_is_refused(): void
    {
        $usd = $this->usdOperator();
        ExchangeRate::create(['base_currency' => 'USD', 'quote_currency' => 'XAF', 'rate' => 600]);
        $merchant = $this->merchant();
        $this->priceFor($merchant, $usd, ['rate' => null]);
        [, $plain] = $this->apiKey($merchant);

        // Le taux global n'est jamais utilisé en repli pour un marchand.
        $this->postJson('/api/v1/gateway/quotes', [
            'type' => 'transfer', 'country' => 'CM', 'carrier' => 'USD_OP', 'amount' => 10000,
        ], $this->headers($plain))->assertStatus(422)->assertJsonPath('error_code', 'PRICING_NOT_CONFIGURED');
    }

    public function test_countries_show_the_merchants_own_conditions(): void
    {
        $merchant = $this->merchant();
        $this->priceFor($merchant, $this->operator, ['fixed_fee' => 7, 'percent_fee' => 0.003]);
        $other = Operator::factory()->create(['country_id' => $this->country->id, 'code' => 'ORG_CM', 'status' => true]);
        [, $plain] = $this->apiKey($merchant);

        $carriers = collect($this->getJson('/api/v1/gateway/countries', $this->headers($plain))->assertOk()->json('data.0.carriers'))->keyBy('code');

        $this->assertEquals(7, $carriers['MTN_CM']['fixed_fee']);
        $this->assertEquals(0.003, $carriers['MTN_CM']['percent_fee']);
        $this->assertTrue($carriers['MTN_CM']['available']);
        $this->assertFalse($carriers['ORG_CM']['available']);
        $this->assertNull($carriers['ORG_CM']['fixed_fee']);
    }

    // ------------------------------------------------------------------ Admin

    public function test_admin_manages_pricing_per_merchant_and_operator(): void
    {
        $merchant = $this->merchant();
        MerchantPricing::where('merchant_id', $merchant->id)->delete();
        $usd = $this->usdOperator();
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);

        $this->putJson("/api/admin/merchants/{$merchant->id}/pricing/{$this->operator->id}", [
            'fixed_fee' => 25, 'percent_fee' => 0.015,
        ])->assertOk()->assertJsonPath('data.fixed_fee', 25);

        // Opérateur en USD pour un wallet XAF : le taux est obligatoire.
        $this->putJson("/api/admin/merchants/{$merchant->id}/pricing/{$usd->id}", ['fixed_fee' => 0, 'percent_fee' => 0.01])
            ->assertStatus(422)->assertJsonValidationErrors(["rate"]);
        $this->putJson("/api/admin/merchants/{$merchant->id}/pricing/{$usd->id}", ['fixed_fee' => 0, 'percent_fee' => 0.01, 'rate' => 590])
            ->assertOk()->assertJsonPath('data.rate', 590);

        $rows = collect($this->getJson("/api/admin/merchants/{$merchant->id}/pricing")->assertOk()->json('data'))->keyBy('operator_id');
        $this->assertEquals(25, $rows[$this->operator->id]['pricing']['fixed_fee']);
        $this->assertEquals(590, $rows[$usd->id]['pricing']['rate']);

        $this->deleteJson("/api/admin/merchants/{$merchant->id}/pricing/{$usd->id}")->assertOk();
        $this->assertDatabaseMissing('merchant_pricing', ['merchant_id' => $merchant->id, 'operator_id' => $usd->id]);
    }

    public function test_non_admins_cannot_manage_pricing(): void
    {
        $merchant = $this->merchant();
        Sanctum::actingAs($merchant, ['*']);

        $this->putJson("/api/admin/merchants/{$merchant->id}/pricing/{$this->operator->id}", ['fixed_fee' => 0, 'percent_fee' => 0])
            ->assertStatus(403);
    }

    public function test_seed_command_copies_operator_fees_and_global_rate_without_touching_existing_pricing(): void
    {
        $usd = $this->usdOperator();
        $noRate = Operator::factory()->create(['country_id' => $this->country->id, 'code' => 'EUR_OP', 'currency' => 'EUR', 'status' => true]);
        ExchangeRate::create(['base_currency' => 'USD', 'quote_currency' => 'XAF', 'rate' => 600]);
        $merchant = $this->merchant();
        MerchantPricing::where('merchant_id', $merchant->id)->delete();
        $this->priceFor($merchant, $this->operator, ['fixed_fee' => 5, 'percent_fee' => 0.002]);

        $this->artisan('merchants:seed-pricing', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(1, MerchantPricing::count());

        $this->artisan('merchants:seed-pricing')->assertSuccessful();

        $existing = MerchantPricing::where('operator_id', $this->operator->id)->first();
        $this->assertEquals(5, $existing->fixed_fee);
        $copied = MerchantPricing::where('operator_id', $usd->id)->first();
        $this->assertEquals(0.5, $copied->fixed_fee);
        $this->assertEquals(0.01, $copied->percent_fee);
        $this->assertEquals(600, $copied->rate);
        $this->assertDatabaseMissing('merchant_pricing', ['operator_id' => $noRate->id]);
    }
}
