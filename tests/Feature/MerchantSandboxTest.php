<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayContract;
use App\Jobs\ProcessTransferJob;
use App\Jobs\SimulateSandboxTransactionJob;
use App\Models\ApiKey;
use App\Models\Country;
use App\Models\Operator;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CarrierRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Sandbox simulée des marchands : une clé sk_test_ crée des transactions fictives
 * qui ne touchent ni Digitwave ni le solde réel, et le portail affiche wallet et
 * transactions des deux environnements.
 */
class MerchantSandboxTest extends TestCase
{
    use RefreshDatabase;

    private const SCOPES = ['transfer.write', 'deposit.write', 'transactions.read', 'wallet.read'];

    protected function setUp(): void
    {
        parent::setUp();

        $country = Country::factory()->create(['name' => 'Cameroon', 'iso' => 'CM', 'status' => true]);
        Operator::factory()->create([
            'country_id' => $country->id,
            'code' => 'MTN_CM',
            'status' => true,
            'fixed_fee' => 50,
            'percent_fee' => 0.01,
            'min_amount' => 100,
            'max_amount' => 100000,
        ]);
    }

    /** Aucune transaction sandbox ne doit jamais atteindre le fournisseur de paiement. */
    private function forbidGatewayCalls(): void
    {
        $gateway = Mockery::mock(PaymentGatewayContract::class);
        $gateway->shouldNotReceive('sendMoney', 'requestWithdrawal', 'checkStatus');
        $this->app->instance(PaymentGatewayContract::class, $gateway);
    }

    private function merchant(string $environment = 'sandbox'): User
    {
        $merchant = User::factory()->merchant()->create(['environment' => $environment]);
        $merchant->wallet()->update(['balance' => 5000, 'sandbox_balance' => 20000]);

        return $merchant;
    }

    private function headers(User $merchant, string $environment, string $idempotencyKey): array
    {
        $key = ApiKey::generateFor($merchant, 'test', $environment, self::SCOPES)['plainTextKey'];

        return ['Authorization' => "Bearer {$key}", 'Idempotency-Key' => $idempotencyKey];
    }

    private function transfer(array $headers, string $number, int $amount = 1000)
    {
        return $this->postJson('/api/v1/gateway/transfers', [
            'country' => 'CM', 'carrier' => 'MTN_CM', 'number' => $number, 'amount' => $amount,
        ], $headers);
    }

    public function test_a_sandbox_transfer_succeeds_on_the_sandbox_balance_only(): void
    {
        $this->forbidGatewayCalls();
        $merchant = $this->merchant();

        $this->transfer($this->headers($merchant, 'sandbox', 'k-1'), '677000001')
            ->assertStatus(200)
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.remaining_balance', 18940); // 20000 - (1000 + 50 + 10)

        $transaction = Transaction::firstOrFail();
        $this->assertSame('sandbox', $transaction->environment);
        $this->assertSame('success', $transaction->status);

        $wallet = $merchant->wallet->fresh();
        $this->assertSame(5000.0, $wallet->balance);
        $this->assertSame(18940.0, $wallet->sandbox_balance);
    }

    public function test_a_sandbox_number_ending_in_0002_fails_and_is_refunded(): void
    {
        $this->forbidGatewayCalls();
        $merchant = $this->merchant();

        $this->transfer($this->headers($merchant, 'sandbox', 'k-1'), '67700'.SimulateSandboxTransactionJob::FAILURE_SUFFIX)
            ->assertStatus(200);

        $this->assertSame('failed', Transaction::firstOrFail()->status);
        $wallet = $merchant->wallet->fresh();
        $this->assertSame(20000.0, $wallet->sandbox_balance);
        $this->assertSame(5000.0, $wallet->balance);
    }

    public function test_a_sandbox_number_ending_in_0003_stays_processing(): void
    {
        $this->forbidGatewayCalls();
        $merchant = $this->merchant();

        $this->transfer($this->headers($merchant, 'sandbox', 'k-1'), '67700'.SimulateSandboxTransactionJob::PENDING_SUFFIX)
            ->assertStatus(200);

        $this->assertSame('processing', Transaction::firstOrFail()->status);
    }

    public function test_a_sandbox_deposit_credits_the_sandbox_balance(): void
    {
        $this->forbidGatewayCalls();
        $merchant = $this->merchant();

        $this->postJson('/api/v1/gateway/deposits', [
            'country' => 'CM', 'carrier' => 'MTN_CM', 'number' => '677000001', 'amount' => 3000,
        ], $this->headers($merchant, 'sandbox', 'k-1'))->assertStatus(200);

        $wallet = $merchant->wallet->fresh();
        $this->assertSame(23000.0, $wallet->sandbox_balance);
        $this->assertSame(5000.0, $wallet->balance);
    }

    public function test_a_sandbox_transfer_is_limited_by_the_sandbox_balance(): void
    {
        $merchant = $this->merchant();
        $merchant->wallet()->update(['balance' => 1000000, 'sandbox_balance' => 100]);

        $this->transfer($this->headers($merchant, 'sandbox', 'k-1'), '677000001')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INSUFFICIENT_FUNDS');
    }

    public function test_a_live_transfer_uses_the_real_balance_and_the_real_gateway_job(): void
    {
        Queue::fake();
        $merchant = $this->merchant('production');

        $this->transfer($this->headers($merchant, 'production', 'k-1'), '677000001')
            ->assertStatus(200)
            ->assertJsonPath('data.environment', 'production');

        Queue::assertPushed(ProcessTransferJob::class);
        Queue::assertNotPushed(SimulateSandboxTransactionJob::class);
        $wallet = $merchant->wallet->fresh();
        $this->assertSame(3940.0, $wallet->balance);
        $this->assertSame(20000.0, $wallet->sandbox_balance);
    }

    public function test_the_real_gateway_job_refuses_a_sandbox_transaction(): void
    {
        $merchant = $this->merchant();
        $transaction = Transaction::create([
            'reference' => 'TX-SBX-GUARD', 'type' => 'transfer', 'environment' => 'sandbox',
            'user_id' => $merchant->id, 'recipient_phone' => '677000001', 'recipient_operator' => 'MTN_CM',
            'country_name' => 'Cameroon', 'amount_sent' => 1000, 'currency_sent' => 'XAF', 'fees' => 60,
            'amount_to_receive' => 1000, 'currency_received' => 'XAF', 'status' => 'processing',
        ]);

        $gateway = Mockery::mock(PaymentGatewayContract::class);
        $gateway->shouldNotReceive('sendMoney');

        (new ProcessTransferJob($transaction))->handle($gateway, new CarrierRouter);

        $this->assertNull($transaction->fresh()->submitted_at);
    }

    public function test_a_key_only_sees_the_transactions_and_balance_of_its_environment(): void
    {
        $this->forbidGatewayCalls();
        $merchant = $this->merchant('production');
        $sandbox = $this->headers($merchant, 'sandbox', 'k-1');

        $this->transfer($sandbox, '677000001')->assertStatus(200);

        $this->getJson('/api/v1/gateway/transactions', $sandbox)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/gateway/transactions', $this->headers($merchant, 'production', 'k-2'))
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/gateway/wallet', $sandbox)
            ->assertStatus(200)
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.balance', 18940);
    }

    public function test_the_portal_shows_both_balances_and_filters_transactions_by_environment(): void
    {
        $this->forbidGatewayCalls();
        $merchant = $this->merchant();
        $this->transfer($this->headers($merchant, 'sandbox', 'k-1'), '677000001')->assertStatus(200);

        Sanctum::actingAs($merchant, ['*']);

        $this->getJson('/api/merchants/wallet')
            ->assertStatus(200)
            ->assertJsonPath('data.balance', 5000)
            ->assertJsonPath('data.sandbox_balance', 18940);

        $this->getJson('/api/merchants/transactions?environment=sandbox')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'success');

        $this->getJson('/api/merchants/transactions?environment=production')->assertJsonCount(0, 'data');
    }

    public function test_the_portal_lists_only_the_merchants_own_transactions(): void
    {
        $this->forbidGatewayCalls();
        $other = $this->merchant();
        $this->transfer($this->headers($other, 'sandbox', 'k-1'), '677000001')->assertStatus(200);

        Sanctum::actingAs($this->merchant(), ['*']);

        $this->getJson('/api/merchants/transactions?environment=sandbox')->assertJsonCount(0, 'data');
    }

    public function test_the_sandbox_balance_can_be_topped_up_within_the_limit(): void
    {
        $merchant = $this->merchant();
        Sanctum::actingAs($merchant, ['*']);

        $this->postJson('/api/merchants/sandbox/top-up', ['amount' => 50000])
            ->assertStatus(200)
            ->assertJsonPath('data.sandbox_balance', 70000);

        $this->postJson('/api/merchants/sandbox/top-up', ['amount' => Wallet::SANDBOX_MAX_BALANCE])
            ->assertStatus(422);

        $this->assertSame(5000.0, $merchant->wallet->fresh()->balance);
    }

    public function test_a_customer_cannot_use_the_merchant_portal(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson('/api/merchants/wallet')->assertStatus(403);
        $this->postJson('/api/merchants/sandbox/top-up', ['amount' => 1000])->assertStatus(403);
    }

    public function test_a_new_merchant_starts_with_a_sandbox_balance(): void
    {
        $this->postJson('/api/merchants/register', [
            'company_name' => 'Acme', 'name' => 'Jo', 'email' => 'jo@acme.test', 'phone' => '690000111',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertStatus(201);

        $wallet = User::where('email', 'jo@acme.test')->firstOrFail()->wallet;
        $this->assertSame((float) Wallet::SANDBOX_STARTING_BALANCE, $wallet->sandbox_balance);
        $this->assertSame(0.0, $wallet->balance);
    }

    public function test_sandbox_transactions_are_excluded_from_admin_stats_and_list(): void
    {
        $this->forbidGatewayCalls();
        $this->transfer($this->headers($this->merchant(), 'sandbox', 'k-1'), '677000001')->assertStatus(200);

        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);

        $this->getJson('/api/admin/dashboard/stats')->assertJsonPath('monthlyVolume', 0);
        $this->getJson('/api/admin/transactions')->assertJsonPath('total', 0);
        $this->getJson('/api/admin/transactions?environment=sandbox')->assertJsonPath('total', 1);
    }
}
