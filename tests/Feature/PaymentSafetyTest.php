<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayContract;
use App\Jobs\ProcessTransferJob;
use App\Jobs\ProcessWithdrawalJob;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CarrierRouter;
use App\Services\Gateways\DigitwaveGateway;
use App\Services\Gateways\GatewayResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Régressions de l'audit (points 1 et 7) : un versement n'est jamais envoyé deux fois,
 * et un résultat Digitwave inconnu n'est jamais remboursé automatiquement.
 */
class PaymentSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function transferTransaction(User $user, string $status = 'processing'): Transaction
    {
        return Transaction::create([
            'reference' => 'TX-SAFETY'.uniqid(),
            'type' => 'transfer',
            'user_id' => $user->id,
            'recipient_phone' => '677000000',
            'recipient_operator' => 'MTN_CM',
            'country_name' => 'Cameroon',
            'amount_sent' => 1000,
            'currency_sent' => 'XAF',
            'fees' => 60,
            'amount_to_receive' => 1000,
            'currency_received' => 'XAF',
            'status' => $status,
        ]);
    }

    private function userWithBalance(float $balance): User
    {
        $user = User::factory()->create();
        $user->wallet()->update(['balance' => $balance]);

        return $user;
    }

    private function gatewayReturning(GatewayResponse $response, int $times = 1): PaymentGatewayContract
    {
        $mock = Mockery::mock(PaymentGatewayContract::class);
        $mock->shouldReceive('sendMoney')->times($times)->andReturn($response);

        return $mock;
    }

    public function test_a_post_timeout_is_not_retried_and_is_reported_as_uncertain(): void
    {
        config(['services.digitwave.url' => 'https://digitwave.test/api/', 'services.digitwave.api_key' => 'key']);
        Http::fake(['digitwave.test/*' => Http::failedConnection('Operation timed out')]);

        $result = (new DigitwaveGateway)->sendMoney('TX-1', 'Cameroon', 'MTN_CM', '677000000', 1000, 'XAF');

        $this->assertFalse($result->success);
        $this->assertTrue($result->uncertain);
        Http::assertSentCount(1);
    }

    public function test_a_server_error_is_uncertain_but_a_client_error_is_a_definitive_failure(): void
    {
        config(['services.digitwave.url' => 'https://digitwave.test/api/', 'services.digitwave.api_key' => 'key']);
        Http::fake([
            'digitwave.test/api/send' => Http::response(['message' => 'Internal'], 502),
            'digitwave.test/api/withdrawal' => Http::response(['message' => 'Invalid number'], 422),
        ]);

        $gateway = new DigitwaveGateway;

        $this->assertTrue($gateway->sendMoney('TX-1', 'Cameroon', 'MTN_CM', '677000000', 1000, 'XAF')->uncertain);
        $this->assertFalse($gateway->requestWithdrawal('DP-1', 'Cameroon', 'MTN_CM', '677000000', 1000, 'XAF')->uncertain);
        Http::assertSentCount(2);
    }

    public function test_an_uncertain_result_leaves_the_transfer_processing_without_refund(): void
    {
        $user = $this->userWithBalance(500);
        $transaction = $this->transferTransaction($user);

        (new ProcessTransferJob($transaction))->handle(
            $this->gatewayReturning(GatewayResponse::uncertain('timeout')),
            new CarrierRouter
        );

        $transaction->refresh();
        $this->assertSame('processing', $transaction->status);
        $this->assertNotNull($transaction->submitted_at);
        $this->assertSame(500.0, (float) $user->wallet->fresh()->balance);
    }

    public function test_a_retried_job_never_sends_the_transfer_a_second_time(): void
    {
        $user = $this->userWithBalance(500);
        $transaction = $this->transferTransaction($user);
        $gateway = $this->gatewayReturning(GatewayResponse::uncertain('timeout'), times: 1);

        (new ProcessTransferJob($transaction))->handle($gateway, new CarrierRouter);
        (new ProcessTransferJob($transaction->fresh()))->handle($gateway, new CarrierRouter);

        // Mockery vérifie à la fin du test que sendMoney n'a été appelé qu'une fois.
        $this->assertSame(500.0, (float) $user->wallet->fresh()->balance);
    }

    public function test_a_definitive_failure_refunds_exactly_once(): void
    {
        $user = $this->userWithBalance(500);
        $transaction = $this->transferTransaction($user);
        $job = new ProcessTransferJob($transaction);

        $job->handle($this->gatewayReturning(GatewayResponse::failure('Numéro invalide')), new CarrierRouter);
        $job->failed(new RuntimeException('relance'));

        $this->assertSame('failed', $transaction->fresh()->status);
        $this->assertSame(1560.0, (float) $user->wallet->fresh()->balance);
    }

    public function test_a_job_failing_after_submission_does_not_refund(): void
    {
        $user = $this->userWithBalance(500);
        $transaction = $this->transferTransaction($user);
        $transaction->update(['submitted_at' => now()]);

        (new ProcessTransferJob($transaction))->failed(new RuntimeException('worker tué'));

        $this->assertSame('processing', $transaction->fresh()->status);
        $this->assertSame(500.0, (float) $user->wallet->fresh()->balance);
    }

    public function test_a_job_failing_before_submission_refunds(): void
    {
        $user = $this->userWithBalance(500);
        $transaction = $this->transferTransaction($user);

        (new ProcessTransferJob($transaction))->failed(new RuntimeException('base indisponible'));

        $this->assertSame('failed', $transaction->fresh()->status);
        $this->assertSame(1560.0, (float) $user->wallet->fresh()->balance);
    }

    public function test_a_deposit_collection_is_requested_only_once(): void
    {
        $user = $this->userWithBalance(0);
        $transaction = Transaction::create([
            'reference' => 'DP-SAFETY1',
            'type' => 'deposit',
            'user_id' => $user->id,
            'recipient_phone' => '677000000',
            'recipient_operator' => 'MTN_CM',
            'country_name' => 'Cameroon',
            'amount_sent' => 1000,
            'currency_sent' => 'XAF',
            'fees' => 60,
            'amount_to_receive' => 1060,
            'currency_received' => 'XAF',
            'status' => 'pending',
        ]);

        $gateway = Mockery::mock(PaymentGatewayContract::class);
        $gateway->shouldReceive('requestWithdrawal')->once()->andReturn(GatewayResponse::uncertain('timeout'));

        (new ProcessWithdrawalJob($transaction))->handle($gateway, new CarrierRouter);
        (new ProcessWithdrawalJob($transaction->fresh()))->handle($gateway, new CarrierRouter);

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertSame(0.0, (float) $user->wallet->fresh()->balance);
    }
}
