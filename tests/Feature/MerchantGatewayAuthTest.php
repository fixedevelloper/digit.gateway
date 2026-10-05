<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Authentification par clé API (ApiKeyAuth) et idempotence (IdempotencyKey) de /api/v1/gateway/*.
 */
class MerchantGatewayAuthTest extends MerchantApiTestCase
{
    public static function endpoints(): array
    {
        return [
            'countries' => ['GET', '/api/v1/gateway/countries'],
            'country' => ['GET', '/api/v1/gateway/countries/CM'],
            'quotes' => ['POST', '/api/v1/gateway/quotes'],
            'transfers' => ['POST', '/api/v1/gateway/transfers'],
            'bank-countries' => ['GET', '/api/v1/gateway/bank-countries'],
            'bank-transfers' => ['POST', '/api/v1/gateway/bank-transfers'],
            'withdrawals' => ['POST', '/api/v1/gateway/withdrawals'],
            'deposits' => ['POST', '/api/v1/gateway/deposits'],
            'wallet' => ['GET', '/api/v1/gateway/wallet'],
            'transactions' => ['GET', '/api/v1/gateway/transactions'],
            'transaction' => ['GET', '/api/v1/gateway/transactions/TX-1'],
        ];
    }

    // ------------------------------------------------------------ Authentification

    #[DataProvider('endpoints')]
    public function test_every_endpoint_rejects_a_missing_key(string $method, string $url): void
    {
        $this->json($method, $url)->assertStatus(401)->assertJsonPath('message', 'Clé API manquante.');
    }

    #[DataProvider('endpoints')]
    public function test_every_endpoint_rejects_an_unknown_key(string $method, string $url): void
    {
        $this->json($method, $url, [], ['Authorization' => 'Bearer sk_live_'.str_repeat('x', 40)])
            ->assertStatus(401)->assertJsonPath('message', 'Clé API invalide ou révoquée.');
    }

    public function test_the_key_can_be_sent_in_the_x_api_key_header(): void
    {
        [, $plain] = $this->apiKey($this->merchant());

        $this->getJson('/api/v1/gateway/wallet', ['X-API-Key' => $plain])->assertOk();
    }

    public function test_a_revoked_key_stops_working(): void
    {
        [$key, $plain] = $this->apiKey($this->merchant());
        $this->getJson('/api/v1/gateway/wallet', $this->headers($plain))->assertOk();

        $key->update(['revoked_at' => now()]);

        $this->getJson('/api/v1/gateway/wallet', $this->headers($plain))->assertStatus(401);
    }

    public function test_a_suspended_merchant_loses_api_access(): void
    {
        $merchant = $this->merchant();
        [, $plain] = $this->apiKey($merchant);

        $merchant->update(['status' => false]);

        $this->getJson('/api/v1/gateway/wallet', $this->headers($plain))->assertStatus(401);
    }

    public function test_the_last_used_date_is_recorded(): void
    {
        [$key, $plain] = $this->apiKey($this->merchant());
        $this->assertNull($key->last_used_at);

        $this->getJson('/api/v1/gateway/wallet', $this->headers($plain));

        $this->assertNotNull($key->fresh()->last_used_at);
    }

    public function test_the_secret_is_stored_hashed_only(): void
    {
        [$key, $plain] = $this->apiKey($this->merchant(), 'sandbox');

        $this->assertStringStartsWith('sk_test_', $plain);
        $this->assertSame(hash('sha256', $plain), $key->key_hash);
        $this->assertDatabaseMissing('api_keys', ['key_hash' => $plain]);
        $this->assertArrayNotHasKey('key_hash', $key->toArray());
    }

    public function test_a_dashboard_session_token_is_not_an_api_key_and_vice_versa(): void
    {
        $merchant = $this->merchant();
        [, $plain] = $this->apiKey($merchant);

        $token = $merchant->createToken('dashboard')->plainTextToken;
        $this->getJson('/api/v1/gateway/wallet', ['Authorization' => "Bearer {$token}"])->assertStatus(401);

        // Et une clé API n'ouvre pas les routes Sanctum.
        $this->getJson('/api/merchants/profile', ['Authorization' => "Bearer {$plain}"])->assertStatus(401);
    }

    // ---------------------------------------------------------------------- Scopes

    public function test_a_key_without_the_scope_is_refused_with_403(): void
    {
        [, $plain] = $this->apiKey($this->merchant(), 'production', ['countries.read']);
        $h = $this->headers($plain, 'idem-1');

        $this->getJson('/api/v1/gateway/countries', $h)->assertOk();
        $this->getJson('/api/v1/gateway/wallet', $h)->assertStatus(403)->assertJsonPath('message', "Cette clé API n'a pas la permission 'wallet.read'.");
        $this->getJson('/api/v1/gateway/transactions', $h)->assertStatus(403);
        $this->postJson('/api/v1/gateway/transfers', $this->transferBody(), $h)->assertStatus(403);
        $this->postJson('/api/v1/gateway/deposits', $this->transferBody(), $h)->assertStatus(403);
        $this->postJson('/api/v1/gateway/withdrawals', $this->transferBody() + ['agensic_code' => 'X'], $h)->assertStatus(403);
        $this->postJson('/api/v1/gateway/quotes', $this->transferBody() + ['type' => 'transfer'], $h)->assertStatus(403);
    }

    public function test_the_scope_is_checked_before_the_idempotency_header(): void
    {
        [, $plain] = $this->apiKey($this->merchant(), 'production', ['wallet.read']);

        $this->postJson('/api/v1/gateway/transfers', $this->transferBody(), $this->headers($plain))->assertStatus(403);
    }

    // ----------------------------------------------------------------- Environnement

    public function test_a_live_key_is_refused_while_the_account_is_still_in_sandbox(): void
    {
        [, $live] = $this->apiKey($this->merchant('sandbox'), 'production');

        $this->getJson('/api/v1/gateway/wallet', $this->headers($live))
            ->assertStatus(403)->assertJsonPath('error_code', 'PRODUCTION_NOT_ENABLED');
    }

    public function test_a_test_key_keeps_working_on_a_production_account_and_sees_its_own_balance(): void
    {
        $merchant = $this->merchant('production', 5000, 777);
        [, $test] = $this->apiKey($merchant, 'sandbox');
        [, $live] = $this->apiKey($merchant, 'production');

        $this->getJson('/api/v1/gateway/wallet', $this->headers($test))
            ->assertOk()->assertJsonPath('data.environment', 'sandbox')->assertJsonPath('data.balance', 777);
        $this->getJson('/api/v1/gateway/wallet', $this->headers($live))
            ->assertOk()->assertJsonPath('data.environment', 'production')->assertJsonPath('data.balance', 5000);
    }

    // ------------------------------------------------------------------ Idempotence

    public static function writeEndpoints(): array
    {
        return [
            'transfers' => ['/api/v1/gateway/transfers'],
            'bank-transfers' => ['/api/v1/gateway/bank-transfers'],
            'withdrawals' => ['/api/v1/gateway/withdrawals'],
            'deposits' => ['/api/v1/gateway/deposits'],
        ];
    }

    #[DataProvider('writeEndpoints')]
    public function test_write_endpoints_require_an_idempotency_key(string $url): void
    {
        [, $plain] = $this->apiKey($this->merchant());

        $this->postJson($url, [], $this->headers($plain))
            ->assertStatus(400)->assertJsonPath('error_code', 'MISSING_IDEMPOTENCY_KEY');
    }

    public function test_a_replayed_request_returns_the_original_response_and_charges_once(): void
    {
        Queue::fake();
        $merchant = $this->merchant();
        [, $plain] = $this->apiKey($merchant);
        $h = $this->headers($plain, 'order-42');

        $first = $this->postJson('/api/v1/gateway/transfers', $this->transferBody(), $h)->assertOk();
        $second = $this->postJson('/api/v1/gateway/transfers', $this->transferBody(), $h)->assertOk()->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, Transaction::count());
        $this->assertSame(100000 - 1060.0, (float) $merchant->wallet->fresh()->balance);
        $this->assertFalse($first->headers->has('Idempotency-Replayed'));
    }

    public function test_the_same_key_with_a_different_body_is_refused(): void
    {
        Queue::fake();
        [, $plain] = $this->apiKey($this->merchant());
        $h = $this->headers($plain, 'order-42');

        $this->postJson('/api/v1/gateway/transfers', $this->transferBody(), $h)->assertOk();
        $this->postJson('/api/v1/gateway/transfers', $this->transferBody(['amount' => 2000]), $h)
            ->assertStatus(422)->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(1, Transaction::count());
    }

    public function test_keys_are_scoped_per_merchant_and_per_environment(): void
    {
        Queue::fake();
        $a = $this->merchant();
        $b = $this->merchant();
        [, $keyA] = $this->apiKey($a);
        [, $keyB] = $this->apiKey($b);
        [, $keyATest] = $this->apiKey($a, 'sandbox');

        $this->postJson('/api/v1/gateway/transfers', $this->transferBody(), $this->headers($keyA, 'same'))->assertOk();
        $this->postJson('/api/v1/gateway/transfers', $this->transferBody(), $this->headers($keyB, 'same'))
            ->assertOk()->assertHeaderMissing('Idempotency-Replayed');
        $this->postJson('/api/v1/gateway/transfers', $this->transferBody(), $this->headers($keyATest, 'same'))
            ->assertOk()->assertHeaderMissing('Idempotency-Replayed');

        $this->assertSame(3, Transaction::count());
    }

    public function test_a_business_error_is_replayed_too_so_a_fixed_request_needs_a_new_key(): void
    {
        Queue::fake();
        $merchant = $this->merchant('production', 10);
        [, $plain] = $this->apiKey($merchant);

        $this->postJson('/api/v1/gateway/transfers', $this->transferBody(), $this->headers($plain, 'k1'))
            ->assertStatus(422)->assertJsonPath('error_code', 'INSUFFICIENT_FUNDS');

        $merchant->wallet()->update(['balance' => 50000]);

        // Même clé, même corps : la réponse d'origine (l'erreur) est rejouée.
        $this->postJson('/api/v1/gateway/transfers', $this->transferBody(), $this->headers($plain, 'k1'))
            ->assertStatus(422)->assertHeader('Idempotency-Replayed', 'true');
        // Nouvelle clé : la requête passe.
        $this->postJson('/api/v1/gateway/transfers', $this->transferBody(), $this->headers($plain, 'k2'))->assertOk();
    }

    public function test_a_server_error_is_not_cached_so_a_retry_can_succeed(): void
    {
        Queue::fake();
        [, $plain] = $this->apiKey($this->merchant());
        $h = $this->headers($plain, 'retry-me');

        // 1er appel : le service plante ; 2e appel : il fonctionne (le contrôleur garde la même instance entre requêtes).
        $real = app(TransactionService::class);
        $this->mock(TransactionService::class, fn ($m) => $m->shouldReceive('createTransfer')->twice()->andReturnUsing(
            fn () => throw new \RuntimeException('boom'),
            fn (...$args) => $real->createTransfer(...$args),
        ));

        $this->postJson('/api/v1/gateway/transfers', $this->transferBody(), $h)
            ->assertStatus(500)->assertJsonPath('error_code', 'PROCESSING_ERROR');

        $this->postJson('/api/v1/gateway/transfers', $this->transferBody(), $h)
            ->assertOk()->assertHeaderMissing('Idempotency-Replayed');
        $this->assertSame(1, Transaction::count());
    }
}
