<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayContract;
use App\Jobs\SendWebhookDelivery;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Notifications\ReconciliationRequiredNotification;
use App\Services\Gateways\GatewayResponse;
use App\Services\TransactionStatusUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebhooksAndReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function tx(User $user, array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'reference' => 'TR-'.uniqid(),
            'type' => 'transfer',
            'channel' => 'merchant_api',
            'environment' => 'production',
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
        ], $overrides));
    }

    private function endpoint(User $merchant, string $env = 'production'): WebhookEndpoint
    {
        return $merchant->webhookEndpoints()->create([
            'url' => 'https://merchant.example/hook',
            'secret' => 'whsec_test',
            'environment' => $env,
        ]);
    }

    // ---------------------------------------------------------------- Webhooks

    public function test_status_change_sends_signed_webhook(): void
    {
        Http::fake(['merchant.example/*' => Http::response('ok', 200)]);
        $merchant = User::factory()->merchant()->create(['environment' => 'production']);
        $this->endpoint($merchant);
        $tx = $this->tx($merchant);

        app(TransactionStatusUpdater::class)->apply($tx, 'success');

        $delivery = WebhookDelivery::firstOrFail();
        $this->assertSame('transaction.success', $delivery->event);
        $this->assertSame(WebhookDelivery::DELIVERED, $delivery->status);

        Http::assertSent(function ($request) use ($tx) {
            [$t, $v1] = array_map(fn ($p) => explode('=', $p, 2)[1], explode(',', $request->header('X-Digit-Signature')[0]));

            return $request['data']['reference'] === $tx->reference
                && $request['data']['status'] === 'success'
                && hash_equals(hash_hmac('sha256', $t.'.'.$request->body(), 'whsec_test'), $v1);
        });
    }

    public function test_no_webhook_for_other_environment_or_non_merchant_channel(): void
    {
        Queue::fake();
        $merchant = User::factory()->merchant()->create(['environment' => 'production']);
        $this->endpoint($merchant, 'sandbox');

        app(TransactionStatusUpdater::class)->apply($this->tx($merchant), 'success');
        app(TransactionStatusUpdater::class)->apply($this->tx($merchant, ['channel' => 'mobile_app']), 'success');

        $this->assertSame(0, WebhookDelivery::count());
        Queue::assertNotPushed(SendWebhookDelivery::class);
    }

    public function test_failed_delivery_is_retried_then_marked_failed(): void
    {
        Queue::fake();
        Http::fake(['merchant.example/*' => Http::response('boom', 500)]);
        $merchant = User::factory()->merchant()->create();
        $delivery = app(\App\Services\Webhooks\WebhookDispatcher::class)->ping($this->endpoint($merchant));

        $job = new SendWebhookDelivery($delivery);
        $job->handle(app(\App\Services\Webhooks\WebhookUrlGuard::class));

        $delivery->refresh();
        $this->assertSame(WebhookDelivery::PENDING, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->next_retry_at);

        $delivery->update(['attempts' => count(SendWebhookDelivery::BACKOFF)]);
        (new SendWebhookDelivery($delivery))->handle(app(\App\Services\Webhooks\WebhookUrlGuard::class));

        $this->assertSame(WebhookDelivery::FAILED, $delivery->fresh()->status);
    }

    public function test_merchant_manages_webhooks_and_secret_is_shown_once(): void
    {
        Queue::fake();
        $merchant = User::factory()->merchant()->create(['environment' => 'production']);
        Sanctum::actingAs($merchant);

        $res = $this->postJson('/api/merchants/webhooks', ['url' => 'https://merchant.example/hook', 'environment' => 'production'])
            ->assertCreated();
        $this->assertStringStartsWith('whsec_', $res->json('data.secret'));

        $this->getJson('/api/merchants/webhooks')->assertOk()->assertJsonMissingPath('data.0.secret');

        $id = $res->json('data.id');
        $this->postJson("/api/merchants/webhooks/{$id}/test")->assertStatus(202);
        $this->assertSame('ping', WebhookDelivery::first()->event);
        $this->deleteJson("/api/merchants/webhooks/{$id}")->assertOk();
    }

    public function test_internal_urls_are_rejected_in_production_mode(): void
    {
        $this->app['env'] = 'production';
        $merchant = User::factory()->merchant()->create(['environment' => 'production']);
        Sanctum::actingAs($merchant);

        $this->postJson('/api/merchants/webhooks', ['url' => 'http://merchant.example/hook', 'environment' => 'production'])->assertStatus(422);
        $this->postJson('/api/merchants/webhooks', ['url' => 'https://127.0.0.1/hook', 'environment' => 'production'])->assertStatus(422);
        $this->postJson('/api/merchants/webhooks', ['url' => 'https://169.254.169.254/latest', 'environment' => 'production'])->assertStatus(422);
        $this->app['env'] = 'testing';
    }

    public function test_merchant_cannot_touch_another_merchants_webhook(): void
    {
        $owner = User::factory()->merchant()->create();
        $endpoint = $this->endpoint($owner);
        Sanctum::actingAs(User::factory()->merchant()->create());

        $this->deleteJson("/api/merchants/webhooks/{$endpoint->id}")->assertNotFound();
        $this->getJson("/api/merchants/webhooks/{$endpoint->id}/deliveries")->assertNotFound();
    }

    // ---------------------------------------------------------- Rapprochement

    public function test_command_flags_unknown_outcome_once_and_notifies_admins(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $stuck = $this->tx($customer, ['channel' => 'mobile_app', 'submitted_at' => now()->subMinutes(10), 'gateway_reference' => null]);
        $this->tx($customer, ['submitted_at' => now()->subMinute()]); // trop récent
        $this->tx($customer, ['submitted_at' => null]);               // jamais soumise
        $this->tx($customer, ['environment' => 'sandbox', 'submitted_at' => now()->subHour()]);

        $this->artisan('transaction:reconcile')->assertExitCode(0);
        $this->artisan('transaction:reconcile')->assertExitCode(0);

        $this->assertNotNull($stuck->fresh()->reconciliation_flagged_at);
        $this->assertSame(1, $admin->notifications()->where('type', ReconciliationRequiredNotification::class)->count());
    }

    public function test_command_rechecks_stale_transaction_with_reference(): void
    {
        $this->mock(PaymentGatewayContract::class, fn ($m) => $m->shouldReceive('checkStatus')->once()
            ->andReturn(new GatewayResponse(success: true, status: 'SUCCESS')));

        $customer = User::factory()->create();
        $tx = $this->tx($customer, ['channel' => 'mobile_app', 'submitted_at' => now()->subHours(2), 'gateway_reference' => 'GW-1']);

        $this->artisan('transaction:reconcile')->assertExitCode(0);

        $this->assertSame('success', $tx->fresh()->status);
    }

    public function test_superadmin_resolves_as_failed_and_refunds_once(): void
    {
        $customer = User::factory()->create();
        $customer->wallet()->update(['balance' => 500]);
        $tx = $this->tx($customer, ['channel' => 'mobile_app', 'submitted_at' => now()->subMinutes(10)]);
        Sanctum::actingAs(User::factory()->superadmin()->create());

        $this->postJson("/api/admin/reconciliation/{$tx->id}/resolve", ['outcome' => 'failed', 'note' => 'Absent chez Digitwave'])
            ->assertOk();

        $this->assertSame('failed', $tx->fresh()->status);
        $this->assertSame(1560.0, (float) $customer->wallet->fresh()->balance);
        $this->assertSame('RECONCILIATION_RESOLVED', $tx->auditLogs()->first()->action);

        // Second appel : la transaction n'est plus dans la file.
        $this->postJson("/api/admin/reconciliation/{$tx->id}/resolve", ['outcome' => 'failed', 'note' => 'Absent chez Digitwave'])
            ->assertNotFound();
        $this->assertSame(1560.0, (float) $customer->wallet->fresh()->balance);
    }

    public function test_resolve_as_success_does_not_refund_and_plain_admin_is_forbidden(): void
    {
        $customer = User::factory()->create();
        $customer->wallet()->update(['balance' => 500]);
        $tx = $this->tx($customer, ['channel' => 'mobile_app', 'submitted_at' => now()->subMinutes(10)]);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/admin/reconciliation/{$tx->id}/resolve", ['outcome' => 'success', 'note' => 'Payé confirmé'])->assertForbidden();
        $this->getJson('/api/admin/reconciliation')->assertOk()->assertJsonPath('data.0.reconciliation_reason', 'unknown_outcome');

        Sanctum::actingAs(User::factory()->superadmin()->create());
        $this->postJson("/api/admin/reconciliation/{$tx->id}/resolve", ['outcome' => 'success', 'note' => 'Payé confirmé'])->assertOk();

        $this->assertSame('success', $tx->fresh()->status);
        $this->assertSame(500.0, (float) $customer->wallet->fresh()->balance);
    }
}
