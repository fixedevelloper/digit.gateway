<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayContract;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Gateways\GatewayResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckTransactionStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeTransaction(User $user, string $type, string $reference): Transaction
    {
        return Transaction::create([
            'reference' => $reference,
            'type' => $type,
            'user_id' => $user->id,
            'recipient_phone' => '677000000',
            'recipient_operator' => 'MTN_CM',
            'country_name' => 'Cameroon',
            'amount_sent' => 1000,
            'currency_sent' => 'XAF',
            'fees' => 60,
            'amount_to_receive' => 1000,
            'currency_received' => 'XAF',
            'status' => 'processing',
            'gateway_reference' => 'GW-'.$reference,
        ]);
    }

    public function test_a_failed_withdrawal_is_refunded_via_polling(): void
    {
        // Régression : withdrawal et deposit partagent le même préfixe de référence
        // ('WD-'). Le remboursement doit se baser sur le type de la transaction, pas
        // sur ce préfixe — sinon un retrait qui échoue via ce chemin (au lieu de la
        // réponse immédiate de ProcessWithdrawalJob) n'est jamais remboursé.
        $this->mock(PaymentGatewayContract::class, function ($mock) {
            $mock->shouldReceive('checkStatus')->andReturn(
                new GatewayResponse(success: true, status: 'FAILED', message: 'Rejeté par l\'opérateur')
            );
        });

        $user = User::factory()->create();
        $user->wallet()->update(['balance' => 500]); // solde après débit initial du retrait
        $tx = $this->makeTransaction($user, 'withdrawal', 'WD-TEST1');

        $this->artisan('transaction:status')->assertExitCode(0);

        $this->assertSame('failed', $tx->fresh()->status);
        // 500 (déjà débité) + 1060 (montant + frais remboursés) = 1560
        $this->assertSame(1560.0, (float) $user->wallet->fresh()->balance);
    }

    public function test_a_failed_deposit_is_never_refunded(): void
    {
        $this->mock(PaymentGatewayContract::class, function ($mock) {
            $mock->shouldReceive('checkStatus')->andReturn(
                new GatewayResponse(success: true, status: 'FAILED', message: 'Rejeté par l\'opérateur')
            );
        });

        $user = User::factory()->create();
        $user->wallet()->update(['balance' => 500]); // rien n'a été débité pour un dépôt
        $tx = $this->makeTransaction($user, 'deposit', 'WD-TEST2');

        $this->artisan('transaction:status')->assertExitCode(0);

        $this->assertSame('failed', $tx->fresh()->status);
        // Aucun remboursement : rien n'avait été prélevé à la création du dépôt.
        $this->assertSame(500.0, (float) $user->wallet->fresh()->balance);
    }

    public function test_a_failed_transfer_is_refunded_via_polling(): void
    {
        $this->mock(PaymentGatewayContract::class, function ($mock) {
            $mock->shouldReceive('checkStatus')->andReturn(
                new GatewayResponse(success: true, status: 'FAILED', message: 'Rejeté par l\'opérateur')
            );
        });

        $user = User::factory()->create();
        $user->wallet()->update(['balance' => 500]);
        $tx = $this->makeTransaction($user, 'transfer', 'TX-TEST3');

        $this->artisan('transaction:status')->assertExitCode(0);

        $this->assertSame('failed', $tx->fresh()->status);
        $this->assertSame(1560.0, (float) $user->wallet->fresh()->balance);
    }

    public function test_a_successful_deposit_credits_the_wallet(): void
    {
        $this->mock(PaymentGatewayContract::class, function ($mock) {
            $mock->shouldReceive('checkStatus')->andReturn(
                new GatewayResponse(success: true, status: 'SUCCESS')
            );
        });

        $user = User::factory()->create();
        $user->wallet()->update(['balance' => 500]);
        $tx = $this->makeTransaction($user, 'deposit', 'WD-TEST4');

        $this->artisan('transaction:status')->assertExitCode(0);

        $this->assertSame('success', $tx->fresh()->status);
        $this->assertSame(1500.0, (float) $user->wallet->fresh()->balance);
    }

    public function test_a_successful_transfer_does_not_touch_the_wallet_again(): void
    {
        $this->mock(PaymentGatewayContract::class, function ($mock) {
            $mock->shouldReceive('checkStatus')->andReturn(
                new GatewayResponse(success: true, status: 'SUCCESS')
            );
        });

        $user = User::factory()->create();
        $user->wallet()->update(['balance' => 500]); // déjà débité à l'initiation
        $tx = $this->makeTransaction($user, 'transfer', 'TX-TEST5');

        $this->artisan('transaction:status')->assertExitCode(0);

        $this->assertSame('success', $tx->fresh()->status);
        // Confirmation de succès uniquement : pas de nouveau crédit/débit.
        $this->assertSame(500.0, (float) $user->wallet->fresh()->balance);
    }
}
