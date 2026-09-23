<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayContract;
use App\Jobs\ProcessTransferJob;
use App\Jobs\ProcessWithdrawalJob;
use App\Models\ApiKey;
use App\Models\Country;
use App\Models\ExchangeRate;
use App\Models\Operator;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CarrierRouter;
use App\Services\ExchangeRateService;
use App\Services\Gateways\GatewayResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Wallet en XAF, opérateur en USD : le client saisit des XAF, voit l'équivalent
 * USD via une cotation, valide, est débité en XAF et Digitwave reçoit des USD.
 *
 * Taux de référence : 1 USD = 600 XAF. Opérateur USD : 0.50 USD fixe + 1 %.
 * Pour 10 000 XAF : 10000 / 600 = 16.666… → 16.66 USD (arrondi inférieur),
 * frais = 0.50 + 0.1666 = 0.67 USD → 402 XAF, total débité = 10 402 XAF.
 */
class CurrencyConversionTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    private Operator $xafOperator;

    private Operator $usdOperator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->country = Country::factory()->create(['name' => 'DR Congo', 'iso' => 'CD', 'status' => true]);

        // Même pays, même code, deux devises.
        $this->xafOperator = Operator::factory()->create([
            'country_id' => $this->country->id,
            'code' => 'VODACOM_CD',
            'currency' => 'XAF',
            'fixed_fee' => 50,
            'percent_fee' => 0.01,
            'min_amount' => 100,
            'max_amount' => 100000,
        ]);

        $this->usdOperator = Operator::factory()->create([
            'country_id' => $this->country->id,
            'code' => 'VODACOM_CD',
            'currency' => 'USD',
            'fixed_fee' => 0.5,
            'percent_fee' => 0.01,
            'min_amount' => 1,
            'max_amount' => 1000,
        ]);

        ExchangeRate::create(['base_currency' => 'USD', 'quote_currency' => 'XAF', 'rate' => 600]);
    }

    private function customer(float $balance = 20000): User
    {
        $user = User::factory()->create(['transaction_pin' => Hash::make('1234')]);
        $user->wallet()->update(['balance' => $balance]);
        Sanctum::actingAs($user, ['*']);

        return $user->fresh();
    }

    private function quote(array $overrides = []): array
    {
        $response = $this->postJson('/api/quote', array_merge([
            'operator_id' => $this->usdOperator->id,
            'amount' => 10000,
        ], $overrides));

        $response->assertStatus(201);

        return $response->json('data');
    }

    public function test_a_quote_shows_the_usd_equivalent_of_the_xaf_amount(): void
    {
        $this->customer();

        $quote = $this->quote();

        $this->assertSame(10000, (int) $quote['amount']);
        $this->assertSame('XAF', $quote['currency']);
        $this->assertSame(16.66, (float) $quote['converted_amount']);
        $this->assertSame('USD', $quote['converted_currency']);
        $this->assertSame(0.67, (float) $quote['converted_fee']);
        $this->assertSame(402, (int) $quote['fee']);
        $this->assertSame(10402, (int) $quote['total']);
        $this->assertSame(600, (int) $quote['rate']);
    }

    public function test_a_transfer_with_a_quote_debits_xaf_and_records_usd_to_send(): void
    {
        Queue::fake();
        $user = $this->customer(20000);
        $quote = $this->quote();

        $response = $this->postJson('/api/transfer', [
            'quote_id' => $quote['quote_id'],
            'number' => '810000000',
            'amount' => 10000,
            'pin' => '1234',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('currency_received', 'USD')
            ->assertJsonPath('amount_received', 16.66);

        $this->assertSame(20000 - 10402.0, (float) $user->wallet->fresh()->balance);

        $transaction = Transaction::firstOrFail();
        $this->assertSame($this->usdOperator->id, $transaction->operator_id);
        $this->assertSame('VODACOM_CD', $transaction->recipient_operator);
        $this->assertSame('DR Congo', $transaction->country_name);
        $this->assertSame(10000.0, (float) $transaction->amount_sent);
        $this->assertSame('XAF', $transaction->currency_sent);
        $this->assertSame(402.0, (float) $transaction->fees);
        $this->assertSame(16.66, (float) $transaction->amount_to_receive);
        $this->assertSame('USD', $transaction->currency_received);
        $this->assertSame(600.0, (float) $transaction->exchange_rate);

        Queue::assertPushed(ProcessTransferJob::class);
    }

    public function test_a_transfer_to_a_usd_operator_without_quote_is_rejected(): void
    {
        Queue::fake();
        $user = $this->customer(20000);

        $response = $this->postJson('/api/transfer', [
            'operator_id' => $this->usdOperator->id,
            'number' => '810000000',
            'amount' => 10000,
            'pin' => '1234',
        ]);

        $response->assertStatus(400);
        $this->assertSame(20000.0, (float) $user->wallet->fresh()->balance);
        Queue::assertNothingPushed();
    }

    public function test_a_quote_cannot_be_used_twice(): void
    {
        Queue::fake();
        $user = $this->customer(50000);
        $quote = $this->quote();

        $payload = ['quote_id' => $quote['quote_id'], 'amount' => 10000, 'pin' => '1234'];

        $this->postJson('/api/transfer', $payload + ['number' => '810000000'])->assertStatus(200);
        $this->postJson('/api/transfer', $payload + ['number' => '820000000'])->assertStatus(400);

        $this->assertSame(50000 - 10402.0, (float) $user->wallet->fresh()->balance);
    }

    public function test_an_expired_quote_is_rejected(): void
    {
        Queue::fake();
        $user = $this->customer(20000);
        $quote = $this->quote();

        $this->travel(config('exchange.quote_ttl') + 1)->seconds();

        $this->postJson('/api/transfer', [
            'quote_id' => $quote['quote_id'],
            'number' => '810000000',
            'amount' => 10000,
            'pin' => '1234',
        ])->assertStatus(400);

        $this->assertSame(20000.0, (float) $user->wallet->fresh()->balance);
    }

    public function test_a_quote_amount_mismatch_is_rejected(): void
    {
        Queue::fake();
        $this->customer(50000);
        $quote = $this->quote();

        $this->postJson('/api/transfer', [
            'quote_id' => $quote['quote_id'],
            'number' => '810000000',
            'amount' => 20000,
            'pin' => '1234',
        ])->assertStatus(400);
    }

    public function test_an_ambiguous_carrier_code_requires_a_currency(): void
    {
        $this->customer();

        $this->postJson('/api/quote', ['country' => 'CD', 'carrier' => 'VODACOM_CD', 'amount' => 10000])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'AMBIGUOUS_OPERATOR');

        $this->postJson('/api/quote', ['country' => 'CD', 'carrier' => 'VODACOM_CD', 'currency' => 'usd', 'amount' => 10000])
            ->assertStatus(201)
            ->assertJsonPath('data.operator.id', $this->usdOperator->id);
    }

    public function test_a_same_currency_operator_works_without_quote(): void
    {
        Queue::fake();
        $user = $this->customer(20000);

        $this->postJson('/api/transfer', [
            'country' => 'DR Congo',
            'carrier' => 'VODACOM_CD',
            'currency' => 'XAF',
            'number' => '810000000',
            'amount' => 1000,
            'pin' => '1234',
        ])->assertStatus(200);

        // fee = 50 + 1000 * 1 % = 60
        $this->assertSame(20000 - 1060.0, (float) $user->wallet->fresh()->balance);
        $this->assertSame('XAF', Transaction::firstOrFail()->currency_received);
    }

    public function test_the_usd_amount_bounds_are_enforced(): void
    {
        $this->customer();

        // 300 XAF = 0.50 USD < min 1 USD
        $this->postJson('/api/quote', ['operator_id' => $this->usdOperator->id, 'amount' => 300])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'AMOUNT_OUT_OF_BOUNDS');
    }

    public function test_a_quote_fails_when_no_rate_is_configured(): void
    {
        $this->customer();
        ExchangeRate::query()->delete();

        $this->postJson('/api/quote', ['operator_id' => $this->usdOperator->id, 'amount' => 10000])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'EXCHANGE_RATE_UNAVAILABLE');
    }

    public function test_the_rate_is_usable_in_both_directions(): void
    {
        $rates = app(ExchangeRateService::class);

        $this->assertSame(600.0, $rates->rate('USD', 'XAF'));
        $this->assertEqualsWithDelta(1 / 600, $rates->rate('XAF', 'USD'), 1e-12);
    }

    public function test_a_deposit_collects_the_usd_amount_plus_fees(): void
    {
        Queue::fake();
        $user = $this->customer(0);
        $quote = $this->quote(['type' => 'deposit']);

        // Dépôt : arrondi supérieur à l'encaissement → 16.67 USD, frais 0.67 USD.
        $this->assertSame(16.67, (float) $quote['converted_amount']);

        $this->postJson('/api/deposit', [
            'quote_id' => $quote['quote_id'],
            'number' => '810000000',
            'amount' => 10000,
            'pin' => '1234',
        ])->assertStatus(200);

        $transaction = Transaction::firstOrFail();
        $this->assertSame(17.34, (float) $transaction->amount_to_receive);
        $this->assertSame('USD', $transaction->currency_received);
        $this->assertSame(0.0, (float) $user->wallet->fresh()->balance);

        $gateway = Mockery::mock(PaymentGatewayContract::class);
        $gateway->shouldReceive('requestWithdrawal')
            ->once()
            ->withArgs(fn ($ref, $country, $carrier, $number, $amount, $currency) => $amount === 17.34 && $currency === 'USD')
            ->andReturn(GatewayResponse::fromArray(['success' => true, 'request_id' => 'DW-1']));

        (new ProcessWithdrawalJob($transaction))->handle($gateway, new CarrierRouter);
    }

    public function test_the_transfer_job_sends_the_usd_amount_to_the_gateway(): void
    {
        Queue::fake();
        $this->customer(20000);
        $quote = $this->quote();

        $this->postJson('/api/transfer', [
            'quote_id' => $quote['quote_id'],
            'number' => '810000000',
            'amount' => 10000,
            'pin' => '1234',
        ])->assertStatus(200);

        $gateway = Mockery::mock(PaymentGatewayContract::class);
        $gateway->shouldReceive('sendMoney')
            ->once()
            ->withArgs(fn ($ref, $country, $carrier, $number, $amount, $currency) => $carrier === 'VODACOM_CD' && $amount === 16.66 && $currency === 'USD')
            ->andReturn(GatewayResponse::fromArray(['success' => true, 'request_id' => 'DW-1']));

        (new ProcessTransferJob(Transaction::firstOrFail()))->handle($gateway, new CarrierRouter);
    }

    public function test_a_failed_usd_transfer_refunds_the_wallet_in_xaf(): void
    {
        Queue::fake();
        $user = $this->customer(20000);
        $quote = $this->quote();

        $this->postJson('/api/transfer', [
            'quote_id' => $quote['quote_id'],
            'number' => '810000000',
            'amount' => 10000,
            'pin' => '1234',
        ])->assertStatus(200);

        $gateway = Mockery::mock(PaymentGatewayContract::class);
        $gateway->shouldReceive('sendMoney')->andReturn(GatewayResponse::failure('Rejected'));

        (new ProcessTransferJob(Transaction::firstOrFail()))->handle($gateway, new CarrierRouter);

        $this->assertSame(20000.0, (float) $user->wallet->fresh()->balance);
    }

    public function test_a_forced_operator_in_another_currency_is_ignored(): void
    {
        Queue::fake();
        $this->customer(20000);
        $forced = Operator::factory()->create(['country_id' => $this->country->id, 'code' => 'AIRTEL_CD', 'currency' => 'XAF']);
        $this->country->update(['forced_operator_id' => $forced->id]);
        $quote = $this->quote();

        $this->postJson('/api/transfer', [
            'quote_id' => $quote['quote_id'],
            'number' => '810000000',
            'amount' => 10000,
            'pin' => '1234',
        ])->assertStatus(200);

        $this->assertSame('VODACOM_CD', (new CarrierRouter)->resolve(Transaction::firstOrFail()));
    }

    public function test_a_merchant_can_quote_and_transfer_through_the_gateway(): void
    {
        Queue::fake();
        $merchant = User::factory()->merchant()->create();
        $merchant->wallet()->update(['balance' => 20000]);
        $key = ApiKey::generateFor($merchant, 'test', 'sandbox', ['transfer.write'])['plainTextKey'];
        $headers = ['Authorization' => 'Bearer '.$key];

        $quote = $this->postJson('/api/v1/gateway/quotes', [
            'country' => 'CD',
            'carrier' => 'VODACOM_CD',
            'currency' => 'USD',
            'amount' => 10000,
        ], $headers)->assertStatus(201)->json('data');

        $this->postJson('/api/v1/gateway/transfers', [
            'quote_id' => $quote['quote_id'],
            'number' => '810000000',
            'amount' => 10000,
        ], $headers + ['Idempotency-Key' => 'k-1'])
            ->assertStatus(200)
            ->assertJsonPath('data.amount_received', 16.66)
            ->assertJsonPath('data.currency_received', 'USD');

        // Pas de scope deposit.write : cotation de dépôt refusée.
        $this->postJson('/api/v1/gateway/quotes', [
            'operator_id' => $this->usdOperator->id,
            'type' => 'deposit',
            'amount' => 10000,
        ], $headers)->assertStatus(403);
    }

    public function test_an_admin_can_create_the_same_code_in_another_currency_only(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);

        $payload = [
            'name' => 'Vodacom M-Pesa',
            'code' => 'VODACOM_CD',
            'country_id' => $this->country->id,
            'phone_length' => 9,
            'fixed_fee' => 0,
            'percent_fee' => 0.01,
            'min_amount' => 1,
            'max_amount' => 1000,
        ];

        // XAF et USD existent déjà (setUp) : doublon refusé.
        $this->postJson('/api/admin/operators', $payload + ['currency' => 'USD'])->assertStatus(422);
        $this->postJson('/api/admin/operators', $payload + ['currency' => 'EUR'])->assertStatus(201);

        $this->assertSame(3, Operator::where('code', 'VODACOM_CD')->count());
    }

    public function test_an_admin_can_set_an_exchange_rate(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/exchange-rates', ['base_currency' => 'usd', 'quote_currency' => 'XAF', 'rate' => 610])
            ->assertStatus(201);

        $this->getJson('/api/admin/exchange-rates')
            ->assertStatus(200)
            ->assertJsonCount(1, 'current')
            ->assertJsonPath('current.0.rate', 610)
            ->assertJsonCount(2, 'history');

        $this->assertSame(610.0, app(ExchangeRateService::class)->rate('USD', 'XAF'));
    }
}
