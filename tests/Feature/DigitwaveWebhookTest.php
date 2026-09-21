<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DigitwaveWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'agswhsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.digitwave.webhook_secret' => self::SECRET]);
    }

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

    private function postWebhook(array $payload, ?string $secret = self::SECRET)
    {
        $body = json_encode($payload);
        $headers = ['CONTENT_TYPE' => 'application/json'];

        if ($secret !== null) {
            $headers['X-Webhook-Signature'] = hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', '/api/webhooks/digitwave', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    public function test_a_successful_deposit_notification_credits_the_wallet(): void
    {
        $user = User::factory()->create();
        $user->wallet()->update(['balance' => 500]);
        $tx = $this->makeTransaction($user, 'deposit', 'WD-TEST1');

        $response = $this->postWebhook([
            'event' => 'transaction_updated',
            'created_at' => now()->timestamp,
            'data' => [
                'request_id' => 'GW-WD-TEST1',
                'status' => 'Success',
            ],
        ]);

        $response->assertOk();
        $this->assertSame('success', $tx->fresh()->status);
        $this->assertSame(1500.0, (float) $user->wallet->fresh()->balance);
    }

    public function test_a_failed_withdrawal_notification_refunds_the_wallet(): void
    {
        $user = User::factory()->create();
        $user->wallet()->update(['balance' => 500]);
        $tx = $this->makeTransaction($user, 'withdrawal', 'WD-TEST2');

        $response = $this->postWebhook([
            'event' => 'transaction_updated',
            'data' => [
                'request_id' => 'GW-WD-TEST2',
                'status' => 'Failed',
            ],
        ]);

        $response->assertOk();
        $this->assertSame('failed', $tx->fresh()->status);
        $this->assertSame(1560.0, (float) $user->wallet->fresh()->balance);
    }

    public function test_a_request_without_signature_header_is_rejected(): void
    {
        $response = $this->postWebhook([
            'event' => 'transaction_updated',
            'data' => ['request_id' => 'GW-UNKNOWN', 'status' => 'Success'],
        ], secret: null);

        $response->assertStatus(401);
    }

    public function test_a_request_with_an_invalid_signature_is_rejected(): void
    {
        $body = json_encode([
            'event' => 'transaction_updated',
            'data' => ['request_id' => 'GW-UNKNOWN', 'status' => 'Success'],
        ]);

        $response = $this->call('POST', '/api/webhooks/digitwave', [], [], [], $this->transformHeadersToServerVars([
            'CONTENT_TYPE' => 'application/json',
            'X-Webhook-Signature' => 'not-the-right-signature',
        ]), $body);

        $response->assertStatus(401);
    }

    public function test_an_unknown_transaction_reference_is_acknowledged_without_error(): void
    {
        $response = $this->postWebhook([
            'event' => 'transaction_updated',
            'data' => ['request_id' => 'GW-DOES-NOT-EXIST', 'status' => 'Success'],
        ]);

        $response->assertOk();
    }

    public function test_a_replayed_notification_does_not_double_credit_the_wallet(): void
    {
        $user = User::factory()->create();
        $user->wallet()->update(['balance' => 500]);
        $tx = $this->makeTransaction($user, 'deposit', 'WD-TEST3');

        $payload = [
            'event' => 'transaction_updated',
            'data' => ['request_id' => 'GW-WD-TEST3', 'status' => 'Success'],
        ];

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $this->assertSame('success', $tx->fresh()->status);
        $this->assertSame(1500.0, (float) $user->wallet->fresh()->balance);
    }
}
