<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayContract;
use App\Jobs\ProcessWithdrawalJob;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CarrierRouter;
use App\Services\Gateways\GatewayResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProcessWithdrawalJobTest extends TestCase
{
    use RefreshDatabase;

    private function fakeFailingGateway(): PaymentGatewayContract
    {
        $mock = Mockery::mock(PaymentGatewayContract::class);
        $mock->shouldReceive('requestWithdrawal')->andReturn(GatewayResponse::failure('Rejected'));

        return $mock;
    }

    public function test_a_failed_withdrawal_refunds_the_wallet(): void
    {
        $gateway = $this->fakeFailingGateway();

        $user = User::factory()->create();
        $user->wallet()->update(['balance' => 500]);

        $transaction = Transaction::create([
            'reference' => 'WD-TEST1',
            'user_id' => $user->id,
            'recipient_phone' => '677000000',
            'recipient_operator' => 'MTN_CM',
            'country_name' => 'Cameroon',
            'amount_sent' => 1000,
            'fees' => 60,
            'amount_to_receive' => 1000,
            'currency_sent' => 'XAF',
            'currency_received' => 'XAF',
            'status' => 'pending',
            'type' => 'withdrawal',
        ]);

        (new ProcessWithdrawalJob($transaction))->handle($gateway, new CarrierRouter);

        $this->assertSame('failed', $transaction->fresh()->status);
        // 500 (solde avant, déjà débité au moment de la requête) + 1060 (remboursement) = 1560
        $this->assertSame(1560.0, (float) $user->wallet->fresh()->balance);
    }

    public function test_a_failed_deposit_does_not_credit_the_wallet(): void
    {
        $gateway = $this->fakeFailingGateway();

        $user = User::factory()->create();
        $user->wallet()->update(['balance' => 500]);

        $transaction = Transaction::create([
            'reference' => 'WD-TEST2',
            'user_id' => $user->id,
            'recipient_phone' => '677000000',
            'recipient_operator' => 'MTN_CM',
            'country_name' => 'Cameroon',
            'amount_sent' => 1000,
            'fees' => 60,
            'amount_to_receive' => 1060,
            'currency_sent' => 'XAF',
            'currency_received' => 'XAF',
            'status' => 'pending',
            'type' => 'deposit',
        ]);

        (new ProcessWithdrawalJob($transaction))->handle($gateway, new CarrierRouter);

        $this->assertSame('failed', $transaction->fresh()->status);
        // Un dépôt n'a jamais débité le wallet à la création : aucun remboursement ne doit avoir lieu.
        $this->assertSame(500.0, (float) $user->wallet->fresh()->balance);
    }
}
