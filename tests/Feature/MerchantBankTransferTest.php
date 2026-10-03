<?php

namespace Tests\Feature;

use App\Enums\CountryServiceStatus;
use App\Enums\TransferService;
use App\Jobs\SimulateSandboxTransactionJob;
use App\Models\ApiKey;
use App\Models\Country;
use App\Models\CountryService;
use App\Models\FeeRule;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MerchantBankTransferTest extends TestCase
{
    use RefreshDatabase;

    private const SCOPES = ['bank_transfer.write', 'countries.read', 'transactions.read'];

    private function setUpCountry(string $status = 'ACTIVE'): Country
    {
        $country = Country::factory()->create(['name' => 'Senegal', 'iso' => 'SN', 'currency' => 'XAF', 'status' => true]);
        CountryService::factory()->create(['country_id' => $country->id, 'service' => TransferService::BankTransfer, 'status' => CountryServiceStatus::from($status)]);
        FeeRule::create(['service' => 'BANK_TRANSFER', 'currency' => 'XAF', 'min_amount' => 0, 'fixed_fee' => 500, 'percent_fee' => 0.01]);

        return $country;
    }

    private function headers(string $environment = 'sandbox', string $idempotencyKey = 'k-1', array $scopes = self::SCOPES): array
    {
        $merchant = User::factory()->merchant()->create(['environment' => $environment]);
        $merchant->wallet()->update(['balance' => 100000, 'sandbox_balance' => 100000]);
        $key = ApiKey::generateFor($merchant, 'test', $environment, $scopes)['plainTextKey'];

        return ['Authorization' => "Bearer {$key}", 'Idempotency-Key' => $idempotencyKey];
    }

    private function body(string $account = '0123456789'): array
    {
        return ['country' => 'SN', 'amount' => 10000, 'beneficiary' => ['full_name' => 'Awa Diop', 'bank_name' => 'CBAO', 'account_number' => $account]];
    }

    public function test_countries_lists_enabled_countries_with_required_fields(): void
    {
        $this->setUpCountry();
        Country::factory()->create(['name' => 'Mali', 'status' => true]); // service non configuré

        $this->getJson('/api/v1/gateway/bank-countries', $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.iso', 'SN')
            ->assertJsonPath('data.0.required_fields', ['full_name', 'bank_name', 'account_number'])
            ->assertJsonMissingPath('data.0.flag');
    }

    public function test_production_bank_transfer_goes_to_the_agent_queue_and_is_trackable(): void
    {
        Queue::fake();
        $this->setUpCountry();
        $headers = $this->headers('production');

        $reference = $this->postJson('/api/v1/gateway/bank-transfers', $this->body(), $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_manual_review')
            ->assertJsonPath('data.processing_mode', 'MANUAL')
            ->assertJsonPath('data.service', 'BANK_TRANSFER')
            ->assertJsonPath('data.fee', 600)
            ->assertJsonPath('data.remaining_balance', 100000 - 10600)
            ->assertJsonPath('data.recipient.bank_name', 'CBAO')
            ->json('data.reference');

        $transaction = Transaction::firstOrFail();
        $this->assertSame('merchant_api', $transaction->channel);
        $this->assertSame('production', $transaction->environment);

        $this->getJson("/api/v1/gateway/transactions/{$reference}", $headers)
            ->assertOk()->assertJsonPath('data.status', 'pending_manual_review');
        $this->getJson('/api/v1/gateway/transactions?status=pending_manual_review', $headers)
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_idempotency_key_is_required_and_replays_the_same_response(): void
    {
        Queue::fake();
        $this->setUpCountry();
        $headers = $this->headers('production');

        $first = $this->postJson('/api/v1/gateway/bank-transfers', $this->body(), $headers)->assertOk();
        $second = $this->postJson('/api/v1/gateway/bank-transfers', $this->body(), $headers)->assertOk()->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json('data.reference'), $second->json('data.reference'));
        $this->assertDatabaseCount('transactions', 1);

        unset($headers['Idempotency-Key']);
        $this->postJson('/api/v1/gateway/bank-transfers', $this->body(), $headers)->assertStatus(400)->assertJsonPath('error_code', 'MISSING_IDEMPOTENCY_KEY');
    }

    public function test_scope_and_validation_are_enforced(): void
    {
        $this->setUpCountry();

        $this->postJson('/api/v1/gateway/bank-transfers', $this->body(), $this->headers('sandbox', 'k', ['transfer.write']))->assertForbidden();

        $this->postJson('/api/v1/gateway/bank-transfers', ['country' => 'SN', 'amount' => 10000, 'beneficiary' => ['full_name' => 'X']], $this->headers())
            ->assertStatus(422)->assertJsonValidationErrors(['beneficiary.bank_name', 'beneficiary.account_number']);
    }

    public function test_disabled_country_is_refused_with_an_error_code_and_no_debit(): void
    {
        $this->setUpCountry('INACTIVE');
        $headers = $this->headers('production');

        $this->postJson('/api/v1/gateway/bank-transfers', $this->body(), $headers)
            ->assertStatus(422)->assertJsonPath('error_code', 'SERVICE_UNAVAILABLE');
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_sandbox_bank_transfer_is_simulated_on_the_sandbox_balance_and_never_queued_for_agents(): void
    {
        $this->setUpCountry();

        $this->postJson('/api/v1/gateway/bank-transfers', $this->body(), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.status', 'processing') // la simulation aboutit juste après (job)
            ->assertJsonPath('data.processing_mode', 'AUTOMATIC')
            ->assertJsonPath('data.remaining_balance', 100000 - 10600);

        $transaction = Transaction::firstOrFail();
        $this->assertSame('success', $transaction->status);
        $this->assertSame(100000.0, $transaction->user->wallet->fresh()->balance); // solde réel intact
    }

    public function test_sandbox_account_ending_0002_fails_and_is_refunded_and_0003_stays_processing(): void
    {
        $this->setUpCountry();
        $headers = $this->headers();

        $this->postJson('/api/v1/gateway/bank-transfers', $this->body('12'.SimulateSandboxTransactionJob::FAILURE_SUFFIX), $headers)->assertOk();
        $failed = Transaction::firstOrFail();
        $this->assertSame('failed', $failed->status);
        $this->assertSame(100000.0, $failed->user->wallet->fresh()->sandbox_balance);

        $headers['Idempotency-Key'] = 'k-2';
        $this->postJson('/api/v1/gateway/bank-transfers', $this->body('12'.SimulateSandboxTransactionJob::PENDING_SUFFIX), $headers)->assertOk();
        $this->assertSame('processing', Transaction::latest('id')->firstOrFail()->status);
    }
}
