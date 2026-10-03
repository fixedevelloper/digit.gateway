<?php

namespace Tests\Feature;

use App\Enums\CountryServiceStatus;
use App\Enums\ProcessingMode;
use App\Enums\TransferService;
use App\Jobs\ProcessTransferJob;
use App\Models\Country;
use App\Models\CountryService;
use App\Models\Operator;
use App\Models\Provider;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransferRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransferRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): Operator
    {
        $country = Country::factory()->create(['name' => 'Gabon', 'status' => true]);

        return Operator::factory()->create([
            'country_id' => $country->id,
            'code' => 'AIRTEL_GA',
            'fixed_fee' => 50,
            'percent_fee' => 0.01,
            'min_amount' => 100,
            'max_amount' => 100000,
        ]);
    }

    private function sender(float $balance = 10000): User
    {
        $user = User::factory()->create(['transaction_pin' => Hash::make('1234')]);
        $user->wallet()->update(['balance' => $balance]);
        Sanctum::actingAs($user, ['*']);

        return $user->fresh();
    }

    private function send(Operator $operator, array $headers = [], array $extra = [])
    {
        return $this->postJson('/api/transfer', array_merge([
            'country' => $operator->country->name,
            'carrier' => $operator->code,
            'number' => '077000000',
            'amount' => 1000,
            'pin' => '1234',
        ], $extra), $headers);
    }

    public function test_digitwave_available_routes_automatically(): void
    {
        Queue::fake();
        $operator = $this->operator();
        $provider = Provider::factory()->create();
        CountryService::factory()->create([
            'country_id' => $operator->country_id,
            'provider_id' => $provider->id,
        ]);
        $this->sender();

        $this->send($operator)->assertOk()->assertJson(['processing_mode' => 'AUTOMATIC', 'transfer_status' => 'processing']);

        $transaction = Transaction::firstOrFail();
        $this->assertSame(ProcessingMode::Automatic, $transaction->processing_mode);
        $this->assertSame($provider->id, $transaction->provider_id);
        Queue::assertPushed(ProcessTransferJob::class);
    }

    public function test_unconfigured_country_keeps_the_legacy_automatic_flow(): void
    {
        Queue::fake();
        $operator = $this->operator();
        $this->sender();

        $this->send($operator)->assertOk()->assertJson(['processing_mode' => 'AUTOMATIC']);
        Queue::assertPushed(ProcessTransferJob::class);
    }

    public function test_country_without_usable_provider_goes_to_manual_review_without_calling_digitwave(): void
    {
        Queue::fake();
        $operator = $this->operator();
        CountryService::factory()->create(['country_id' => $operator->country_id, 'provider_id' => null]);
        $user = $this->sender();

        $this->send($operator)->assertOk()->assertJson(['processing_mode' => 'MANUAL', 'transfer_status' => 'pending_manual_review']);

        $transaction = Transaction::firstOrFail();
        $this->assertTrue($transaction->isManual());
        $this->assertSame(Transaction::STATUS_PENDING_MANUAL_REVIEW, $transaction->status);
        $this->assertNull($transaction->provider_id);
        Queue::assertNotPushed(ProcessTransferJob::class);
        // Fonds réservés : débités à la création (1000 + frais 60).
        $this->assertEquals(10000 - 1060, (float) $user->wallet()->first()->balance);
        $this->assertDatabaseHas('transfer_audit_logs', ['transfer_id' => $transaction->id, 'action' => 'TRANSFER_CREATED', 'new_status' => 'pending_manual_review']);
    }

    public function test_manual_status_and_inactive_provider_route_to_manual(): void
    {
        $operator = $this->operator();
        $router = app(TransferRoutingService::class);

        $config = CountryService::factory()->create([
            'country_id' => $operator->country_id,
            'status' => CountryServiceStatus::Manual,
        ]);
        $this->assertTrue($router->route($operator->country, TransferService::MobileMoney)->isManual());

        $config->update([
            'status' => CountryServiceStatus::Active,
            'provider_id' => Provider::factory()->inactive()->create()->id,
        ]);
        $this->assertTrue($router->route($operator->country, TransferService::MobileMoney)->isManual());
    }

    public function test_provider_without_implementation_is_never_called(): void
    {
        $operator = $this->operator();
        $provider = Provider::factory()->create(['code' => 'unknown-provider']);
        CountryService::factory()->create(['country_id' => $operator->country_id, 'provider_id' => $provider->id]);

        $decision = app(TransferRoutingService::class)->route($operator->country, TransferService::MobileMoney);

        $this->assertTrue($decision->isManual());
    }

    public function test_globally_disabled_digitwave_sends_unconfigured_countries_to_manual(): void
    {
        $operator = $this->operator();
        Provider::factory()->inactive()->create();

        $this->assertTrue(app(TransferRoutingService::class)->route($operator->country, TransferService::MobileMoney)->isManual());
    }

    public function test_inactive_service_is_rejected(): void
    {
        Queue::fake();
        $operator = $this->operator();
        CountryService::factory()->create(['country_id' => $operator->country_id, 'status' => CountryServiceStatus::Inactive]);
        $user = $this->sender();

        $this->send($operator)->assertStatus(400);

        $this->assertDatabaseCount('transactions', 0);
        $this->assertEquals(10000, (float) $user->wallet()->first()->balance);
    }

    public function test_bank_transfer_requires_an_enabled_service(): void
    {
        $country = Country::factory()->create(['status' => true]);
        $router = app(TransferRoutingService::class);

        $this->expectException(\App\Exceptions\TransactionValidationException::class);
        $router->route($country, TransferService::BankTransfer);
    }

    public function test_bank_transfer_active_country_without_provider_is_manual(): void
    {
        $country = Country::factory()->create(['status' => true]);
        CountryService::factory()->create(['country_id' => $country->id, 'service' => TransferService::BankTransfer]);

        $this->assertTrue(app(TransferRoutingService::class)->route($country, TransferService::BankTransfer)->isManual());
    }

    public function test_same_idempotency_key_creates_a_single_transfer_and_debits_once(): void
    {
        Queue::fake();
        $operator = $this->operator();
        CountryService::factory()->create(['country_id' => $operator->country_id]);
        $user = $this->sender();

        $first = $this->send($operator, ['Idempotency-Key' => 'abc-123'])->assertOk();
        $second = $this->send($operator, ['Idempotency-Key' => 'abc-123'])->assertOk();

        $this->assertSame($first->json('request_id'), $second->json('request_id'));
        $this->assertDatabaseCount('transactions', 1);
        $this->assertEquals(10000 - 1060, (float) $user->wallet()->first()->balance);
    }

    public function test_idempotency_key_reused_with_another_amount_is_refused(): void
    {
        Queue::fake();
        $operator = $this->operator();
        $this->sender();

        $this->send($operator, ['Idempotency-Key' => 'k1'])->assertOk();
        $this->send($operator, ['Idempotency-Key' => 'k1'], ['amount' => 2000])->assertStatus(400);

        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_daily_limit_is_enforced(): void
    {
        Queue::fake();
        $operator = $this->operator();
        CountryService::factory()->create(['country_id' => $operator->country_id, 'daily_limit' => 1500]);
        $this->sender();

        $this->send($operator)->assertOk();
        $this->send($operator, [], ['amount' => 600])->assertStatus(400);
    }

    public function test_processing_job_never_calls_the_gateway_for_a_manual_transfer(): void
    {
        $operator = $this->operator();
        CountryService::factory()->create(['country_id' => $operator->country_id]);
        $this->sender();
        $this->send($operator)->assertOk();

        $gateway = \Mockery::mock(\App\Contracts\PaymentGatewayContract::class);
        $gateway->shouldNotReceive('sendMoney');

        (new ProcessTransferJob(Transaction::firstOrFail()))->handle($gateway, app(\App\Services\CarrierRouter::class));

        $this->assertSame(Transaction::STATUS_PENDING_MANUAL_REVIEW, Transaction::firstOrFail()->status);
    }
}
