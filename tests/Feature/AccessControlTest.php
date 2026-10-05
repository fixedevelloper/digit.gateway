<?php

namespace Tests\Feature;

use App\Events\TransactionStatusUpdated;
use App\Models\ApiKey;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Régressions de l'audit : contrôle d'accès (clés de production, PIN, IDOR, comptes suspendus,
 * canal temps réel, changement de téléphone, ajustements de wallet, brute-force).
 */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private function transactionFor(User $user): Transaction
    {
        return Transaction::create([
            'reference' => 'TX-ACCESS'.uniqid(),
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
            'status' => 'processing',
        ]);
    }

    public function test_a_production_key_of_an_account_back_in_sandbox_cannot_move_money(): void
    {
        $merchant = User::factory()->merchant()->create(['environment' => 'production']);
        $key = ApiKey::generateFor($merchant, 'test', 'production', ['transfer.write'])['plainTextKey'];
        $merchant->update(['environment' => 'sandbox']);

        $this->withHeaders(['Authorization' => "Bearer {$key}", 'Idempotency-Key' => 'k-2'])
            ->postJson('/api/v1/gateway/transfers', ['operator_id' => 1, 'number' => '677000000', 'amount' => 1000])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PRODUCTION_NOT_ENABLED');
    }

    public function test_the_pin_is_locked_after_five_wrong_attempts(): void
    {
        $user = User::factory()->create(['transaction_pin' => Hash::make('1234')]);
        Sanctum::actingAs($user, ['*']);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/transfer', ['pin' => '0000', 'amount' => $attempt])->assertStatus(403);
        }

        // Même avec le bon PIN, le compte reste bloqué.
        $this->postJson('/api/transfer', ['pin' => '1234', 'amount' => 6])->assertStatus(429);
        $this->postJson('/api/profile/update-pin', [
            'old_pin' => '1234', 'pin' => '5678', 'pin_confirmation' => '5678',
        ])->assertStatus(429);
    }

    public function test_a_user_cannot_read_another_users_transaction(): void
    {
        $owner = User::factory()->create();
        $transaction = $this->transactionFor($owner);

        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson("/api/transactions/{$transaction->id}/status")->assertStatus(404);
        $this->getJson("/api/get_request?request_id={$transaction->reference}")->assertStatus(404);
    }

    public function test_the_owner_can_read_their_transaction(): void
    {
        $owner = User::factory()->create();
        $transaction = $this->transactionFor($owner);

        Sanctum::actingAs($owner, ['*']);

        $this->getJson("/api/transactions/{$transaction->reference}/status")
            ->assertStatus(200)
            ->assertJsonPath('data.request_id', $transaction->reference);
    }

    public function test_a_suspended_user_is_rejected_even_with_a_valid_token(): void
    {
        Sanctum::actingAs(User::factory()->create(['status' => false]), ['*']);

        $this->getJson('/api/profile')->assertStatus(403);
    }

    public function test_a_suspended_user_cannot_log_in(): void
    {
        $user = User::factory()->create(['status' => false]);

        $this->postJson('/api/login', ['phone' => $user->phone, 'password' => 'password'])->assertStatus(403);
    }

    public function test_suspending_a_user_revokes_their_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('mobile');

        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);
        $this->putJson("/api/admin/users/{$user->id}", ['status' => false])->assertStatus(200);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_transaction_updates_are_broadcast_on_the_owners_private_channel(): void
    {
        $user = User::factory()->create();
        $channels = (new TransactionStatusUpdated($this->transactionFor($user)))->broadcastOn();

        $this->assertSame('private-user.'.$user->id, $channels[0]->name);
    }

    public function test_changing_the_phone_number_requires_the_current_password(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/profile/update', ['name' => 'X', 'phone' => '699999999'])->assertStatus(400);
        $this->postJson('/api/profile/update', ['name' => 'X', 'phone' => '699999999', 'current_password' => 'wrong'])->assertStatus(400);
        $this->assertNotSame('699999999', $user->fresh()->phone);

        $this->postJson('/api/profile/update', ['name' => 'X', 'phone' => '699999999', 'current_password' => 'password'])->assertStatus(200);
        $this->assertSame('699999999', $user->fresh()->phone);
    }

    public function test_the_name_can_be_changed_without_password(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/profile/update', ['name' => 'Nouveau nom', 'phone' => $user->phone])->assertStatus(200);
    }

    public function test_a_plain_admin_can_only_request_an_adjustment_never_apply_it(): void
    {
        $target = User::factory()->create();
        $target->wallet()->update(['balance' => 5000]);
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);

        $this->postJson("/api/admin/wallets/{$target->wallet->id}/adjust", [
            'type' => 'credit', 'amount' => 1000, 'reason' => 'Test de régularisation',
        ])->assertStatus(202)->assertJsonPath('status', 'pending_approval');

        $this->assertSame(5000.0, (float) $target->wallet->fresh()->balance);
    }

    public function test_one_ip_cannot_spray_passwords_across_many_accounts(): void
    {
        foreach (range(1, 20) as $i) {
            $this->postJson('/api/login', ['phone' => "60000000{$i}", 'password' => 'x'])->assertStatus(401);
        }

        $this->postJson('/api/login', ['phone' => '699000000', 'password' => 'x'])->assertStatus(429);
    }
}
