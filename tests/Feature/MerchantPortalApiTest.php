<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * Espace self-service marchand : inscription, connexion, clés API (/api/merchants/*).
 */
class MerchantPortalApiTest extends MerchantApiTestCase
{
    private function registration(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Acme SARL', 'name' => 'Jean Dupont', 'email' => 'jean@acme.test', 'phone' => '690111222',
            'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1',
        ], $overrides);
    }

    // ---------------------------------------------------------------- Inscription

    public function test_registration_creates_an_active_sandbox_merchant_with_a_test_balance(): void
    {
        $res = $this->postJson('/api/merchants/register', $this->registration())->assertCreated();

        $res->assertJsonPath('merchant.environment', 'sandbox')->assertJsonPath('merchant.email', 'jean@acme.test')
            ->assertJsonMissingPath('merchant.password')->assertJsonStructure(['token']);

        $merchant = User::where('email', 'jean@acme.test')->firstOrFail();
        $this->assertSame('merchant', $merchant->role);
        $this->assertTrue($merchant->status);
        $this->assertTrue(Hash::check('motdepasse1', $merchant->password));
        $this->assertSame((float) Wallet::SANDBOX_STARTING_BALANCE, (float) $merchant->wallet->sandbox_balance);
        $this->assertSame(0.0, (float) $merchant->wallet->balance);

        // Le jeton renvoyé ouvre le portail.
        $this->getJson('/api/merchants/profile', ['Authorization' => 'Bearer '.$res->json('token')])->assertOk();
    }

    public function test_registration_validation(): void
    {
        User::factory()->merchant()->create(['email' => 'pris@acme.test', 'phone' => '690000000']);
        $i = 0;

        foreach ([
            'company_name' => [['company_name' => ''], 'company_name'],
            'name' => [['name' => ''], 'name'],
            'email invalide' => [['email' => 'pas-un-email'], 'email'],
            'email pris' => [['email' => 'pris@acme.test'], 'email'],
            'téléphone pris' => [['phone' => '690000000'], 'phone'],
            'mot de passe court' => [['password' => 'court', 'password_confirmation' => 'court'], 'password'],
            'confirmation différente' => [['password_confirmation' => 'autre-chose'], 'password'],
        ] as $label => [$override, $field]) {
            // Téléphone distinct à chaque essai : la limite `auth` (5/min) est calculée par identifiant.
            $override += ['phone' => '6911'.str_pad((string) (++$i), 5, '0', STR_PAD_LEFT)];

            $this->postJson('/api/merchants/register', $this->registration($override))
                ->assertStatus(422)->assertJsonPath('status', 'error')->assertJsonValidationErrors($field, 'errors');
            $this->assertSame(1, User::where('role', 'merchant')->count(), "inscription refusée : {$label}");
        }
    }

    // ------------------------------------------------------------------ Connexion

    private function account(array $overrides = []): User
    {
        return User::factory()->merchant()->create(array_merge(['email' => 'm@acme.test', 'password' => Hash::make('secret123')], $overrides));
    }

    public function test_login_returns_a_token_and_replaces_previous_sessions(): void
    {
        $merchant = $this->account();
        $old = $merchant->createToken('old')->plainTextToken;

        $res = $this->postJson('/api/merchants/login', ['email' => 'm@acme.test', 'password' => 'secret123'])
            ->assertOk()->assertJsonPath('merchant.email', 'm@acme.test')->assertJsonMissingPath('merchant.password');

        $this->assertSame(1, $merchant->tokens()->count());
        $this->getJson('/api/merchants/profile', ['Authorization' => 'Bearer '.$res->json('token')])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/merchants/profile', ['Authorization' => "Bearer {$old}"])->assertStatus(401);
    }

    public function test_login_failures(): void
    {
        $this->account();
        User::factory()->create(['email' => 'client@acme.test', 'password' => Hash::make('secret123'), 'role' => 'customer']);
        $this->account(['email' => 'off@acme.test', 'status' => false]);

        $this->postJson('/api/merchants/login', ['email' => 'm@acme.test', 'password' => 'faux'])->assertStatus(401);
        $this->postJson('/api/merchants/login', ['email' => 'inconnu@acme.test', 'password' => 'secret123'])->assertStatus(401);
        // Un client de l'app mobile n'est pas un marchand.
        $this->postJson('/api/merchants/login', ['email' => 'client@acme.test', 'password' => 'secret123'])->assertStatus(401);
        $this->postJson('/api/merchants/login', ['email' => 'off@acme.test', 'password' => 'secret123'])->assertStatus(403);
        $this->postJson('/api/merchants/login', ['email' => 'pas-un-email', 'password' => 'x'])->assertStatus(422);
    }

    public function test_login_is_throttled_per_account(): void
    {
        $this->account();

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/merchants/login', ['email' => 'm@acme.test', 'password' => 'faux'])->assertStatus(401);
        }

        $this->postJson('/api/merchants/login', ['email' => 'm@acme.test', 'password' => 'secret123'])->assertStatus(429);
    }

    public function test_logout_revokes_the_session_token(): void
    {
        $merchant = $this->account();
        $token = $merchant->createToken('t')->plainTextToken;

        $this->postJson('/api/merchants/logout', [], ['Authorization' => "Bearer {$token}"])->assertOk();

        $this->assertSame(0, $merchant->tokens()->count());
    }

    // ------------------------------------------------------------------ Clés API

    public function test_a_merchant_creates_a_key_whose_secret_is_shown_once(): void
    {
        $merchant = $this->account();
        Sanctum::actingAs($merchant, ['*']);

        $res = $this->postJson('/api/merchants/api-keys', ['name' => 'Serveur', 'environment' => 'sandbox', 'scopes' => ['wallet.read', 'transfer.write']])
            ->assertCreated();

        $plain = $res->json('data.key');
        $this->assertStringStartsWith('sk_test_', $plain);
        $this->assertSame(['wallet.read', 'transfer.write'], $res->json('data.scopes'));

        $list = $this->getJson('/api/merchants/api-keys')->assertOk();
        $list->assertJsonCount(1, 'data')->assertJsonPath('data.0.key_prefix', substr($plain, 0, 12));
        $this->assertStringNotContainsString($plain, $list->getContent());
        $this->assertStringNotContainsString('key_hash', $list->getContent());

        // La clé fraîchement créée fonctionne sur la passerelle (avec ses seuls scopes).
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/gateway/wallet', ['Authorization' => "Bearer {$plain}"])->assertOk();
        $this->getJson('/api/v1/gateway/transactions', ['Authorization' => "Bearer {$plain}"])->assertStatus(403);
    }

    public function test_key_creation_validation_and_the_production_gate(): void
    {
        Sanctum::actingAs($this->account(), ['*']);
        $post = fn (array $b) => $this->postJson('/api/merchants/api-keys', $b);

        $post(['environment' => 'sandbox', 'scopes' => ['wallet.read']])->assertStatus(422)->assertJsonValidationErrors('name');
        $post(['name' => 'k', 'environment' => 'sandbox', 'scopes' => []])->assertStatus(422)->assertJsonValidationErrors('scopes');
        $post(['name' => 'k', 'environment' => 'sandbox', 'scopes' => ['admin.everything']])->assertStatus(422);
        $post(['name' => 'k', 'environment' => 'staging', 'scopes' => ['wallet.read']])->assertStatus(422)->assertJsonValidationErrors('environment');
        // Compte pas encore validé en production : pas de clé live.
        $post(['name' => 'k', 'environment' => 'production', 'scopes' => ['wallet.read']])->assertStatus(403);

        Sanctum::actingAs($this->account(['email' => 'prod@acme.test', 'environment' => 'production']), ['*']);
        $this->postJson('/api/merchants/api-keys', ['name' => 'k', 'environment' => 'production', 'scopes' => ['wallet.read']])
            ->assertCreated()->assertJsonPath('data.environment', 'production');
    }

    public function test_revoking_a_key_stops_it_and_only_the_owner_can_revoke(): void
    {
        $owner = $this->merchant();
        [$key, $plain] = $this->apiKey($owner);
        $intruder = User::factory()->merchant()->create();

        Sanctum::actingAs($intruder, ['*']);
        $this->deleteJson("/api/merchants/api-keys/{$key->id}")->assertNotFound();
        $this->assertNull($key->fresh()->revoked_at);

        Sanctum::actingAs($owner, ['*']);
        $this->deleteJson("/api/merchants/api-keys/{$key->id}")->assertOk();
        $this->assertNotNull($key->fresh()->revoked_at);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/gateway/wallet', ['Authorization' => "Bearer {$plain}"])->assertStatus(401);
    }

    public function test_the_key_list_only_shows_the_merchants_own_keys(): void
    {
        $a = $this->merchant();
        $this->apiKey($a);
        $this->apiKey($this->merchant());

        Sanctum::actingAs($a, ['*']);

        $this->getJson('/api/merchants/api-keys')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(2, ApiKey::count());
    }

    // ------------------------------------------------------------- Accès au portail

    public function test_portal_routes_require_a_merchant_session(): void
    {
        foreach (['/api/merchants/profile', '/api/merchants/api-keys', '/api/merchants/wallet', '/api/merchants/webhooks'] as $url) {
            $this->getJson($url)->assertStatus(401);
        }

        Sanctum::actingAs(User::factory()->create(), ['*']);
        foreach (['/api/merchants/profile', '/api/merchants/api-keys', '/api/merchants/wallet'] as $url) {
            $this->getJson($url)->assertStatus(403);
        }
    }

    public function test_a_suspended_merchant_session_is_cut_off(): void
    {
        $merchant = $this->account();
        Sanctum::actingAs($merchant, ['*']);
        $merchant->update(['status' => false]);

        $this->getJson('/api/merchants/profile')->assertStatus(403);
    }
}
