<?php

namespace Tests\Feature;

use App\Enums\CountryServiceStatus;
use App\Enums\TransferService;
use App\Models\Agency;
use App\Models\Country;
use App\Models\CountryService;
use App\Models\FeeRule;
use App\Models\Operator;
use App\Models\Provider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Corrections issues de la revue de code : idempotence, file manuelle, routage, données exposées. */
class ReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Operator $operator;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $country = Country::factory()->create(['name' => 'Gabon', 'iso' => 'GA', 'currency' => 'XAF', 'status' => true]);
        $this->operator = Operator::factory()->create(['country_id' => $country->id, 'code' => 'AIRTEL_GA', 'min_amount' => 100, 'max_amount' => 100000]);
        Agency::create(['code' => 'AG-1', 'name' => 'Agence', 'status' => 'active']);

        $this->customer = User::factory()->create(['transaction_pin' => Hash::make('1234')]);
        $this->customer->wallet()->update(['balance' => 100000]);
        Sanctum::actingAs($this->customer, ['*']);
    }

    private function mmPayload(array $extra = []): array
    {
        return array_merge(['country' => 'Gabon', 'carrier' => 'AIRTEL_GA', 'number' => '077000000', 'amount' => 1000, 'pin' => '1234'], $extra);
    }

    private function withdrawalPayload(): array
    {
        return $this->mmPayload(['agensic_code' => 'AG-1']);
    }

    private function manualTransfer(): Transaction
    {
        CountryService::factory()->create(['country_id' => $this->operator->country_id]);
        $this->postJson('/api/transfer', $this->mmPayload())->assertOk();

        return Transaction::firstOrFail();
    }

    private function asAgent(?User $agent = null): User
    {
        $agent ??= User::factory()->agent()->create();
        Sanctum::actingAs($agent, ['*']);

        return $agent;
    }

    // --- 1. anti-doublon retrait/dépôt ---

    public function test_an_idempotency_key_header_does_not_disable_the_duplicate_guard_on_withdrawals_and_deposits(): void
    {
        $headers = ['Idempotency-Key' => 'abc'];

        $this->postJson('/api/withdrawal', $this->withdrawalPayload(), $headers)->assertOk();
        $this->postJson('/api/withdrawal', $this->withdrawalPayload(), $headers)->assertStatus(409);

        $this->postJson('/api/deposit', $this->mmPayload(), $headers)->assertOk();
        $this->postJson('/api/deposit', $this->mmPayload(), $headers)->assertStatus(409);

        $this->assertSame(2, Transaction::count());
    }

    public function test_an_empty_idempotency_key_header_keeps_the_duplicate_guard_on_transfers(): void
    {
        $this->postJson('/api/transfer', $this->mmPayload(), ['Idempotency-Key' => ''])->assertOk();
        $this->postJson('/api/transfer', $this->mmPayload(), ['Idempotency-Key' => ''])->assertStatus(409);

        $this->assertSame(1, Transaction::count());
    }

    // --- 6. validation de la clé ---

    public function test_an_oversized_idempotency_key_is_refused_cleanly(): void
    {
        $this->postJson('/api/transfer', $this->mmPayload(), ['Idempotency-Key' => str_repeat('a', 101)])
            ->assertStatus(400);

        $this->assertSame(0, Transaction::count());
        $this->assertEquals(100000, (float) $this->customer->wallet()->first()->balance);
    }

    public function test_a_key_cannot_be_reused_across_services(): void
    {
        $bank = Country::factory()->create(['name' => 'Senegal', 'iso' => 'SN', 'currency' => 'XAF', 'status' => true]);
        CountryService::factory()->create(['country_id' => $bank->id, 'service' => TransferService::BankTransfer, 'status' => CountryServiceStatus::Manual]);
        FeeRule::create(['service' => 'BANK_TRANSFER', 'currency' => 'XAF', 'fixed_fee' => 100]);

        $this->postJson('/api/transfer', $this->mmPayload(), ['Idempotency-Key' => 'shared'])->assertOk();

        $this->postJson('/api/bank-transfer', ['country' => 'SN', 'amount' => 1000, 'pin' => '1234', 'beneficiary' => ['full_name' => 'A', 'bank_name' => 'B', 'account_number' => '1']], ['Idempotency-Key' => 'shared'])
            ->assertStatus(400);
        $this->assertSame(1, Transaction::count());

        // Et inversement : le mauvais transfert n'est jamais rejoué.
        $this->postJson('/api/transfer', $this->mmPayload(), ['Idempotency-Key' => 'shared'])->assertOk();
        $this->assertSame(1, Transaction::count());
    }

    // --- 2. file manuelle : transferts bloqués ---

    public function test_an_agent_can_give_back_an_assigned_transfer_and_another_agent_can_take_it(): void
    {
        $t = $this->manualTransfer();
        $first = $this->asAgent();
        $this->postJson("/api/agent/transfers/{$t->id}/claim")->assertOk();
        $this->postJson("/api/agent/transfers/{$t->id}/release")->assertOk()->assertJsonPath('data.status', 'pending_manual_review')->assertJsonPath('data.assigned_agent_id', null);

        $this->asAgent();
        $this->postJson("/api/agent/transfers/{$t->id}/claim")->assertOk();
        $this->assertNotSame($first->id, $t->fresh()->assigned_agent_id);
        $this->assertDatabaseHas('transfer_audit_logs', ['transfer_id' => $t->id, 'action' => 'TRANSFER_RELEASED', 'role' => 'agent']);
    }

    public function test_an_agent_cannot_release_someone_elses_or_a_processing_transfer(): void
    {
        $t = $this->manualTransfer();
        $first = $this->asAgent();
        $this->postJson("/api/agent/transfers/{$t->id}/claim")->assertOk();

        $this->asAgent();
        $this->postJson("/api/agent/transfers/{$t->id}/release")->assertForbidden();

        $this->asAgent($first);
        $this->postJson("/api/agent/transfers/{$t->id}/start")->assertOk();
        $this->postJson("/api/agent/transfers/{$t->id}/release")->assertStatus(409);
    }

    public function test_the_admin_can_release_a_stuck_processing_transfer_with_a_reason(): void
    {
        $t = $this->manualTransfer();
        $this->asAgent();
        $this->postJson("/api/agent/transfers/{$t->id}/claim");
        $this->postJson("/api/agent/transfers/{$t->id}/start");

        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);
        $this->postJson("/api/admin/transfers/{$t->id}/release")->assertStatus(422);
        $this->postJson("/api/admin/transfers/{$t->id}/release", ['reason' => 'Agent injoignable'])
            ->assertOk()->assertJsonPath('data.status', 'pending_manual_review');

        $this->assertDatabaseHas('transfer_audit_logs', ['transfer_id' => $t->id, 'action' => 'TRANSFER_RELEASED', 'comment' => 'Agent injoignable', 'role' => 'admin']);

        // Le client peut de nouveau annuler et récupère ses fonds.
        Sanctum::actingAs($this->customer, ['*']);
        $this->postJson("/api/transfers/{$t->id}/cancel")->assertOk();
        $this->assertEquals(100000, (float) $this->customer->wallet()->first()->balance);
    }

    public function test_customer_and_agent_cannot_use_the_admin_release(): void
    {
        $t = $this->manualTransfer();

        $this->postJson("/api/admin/transfers/{$t->id}/release", ['reason' => 'x y z'])->assertForbidden();
        $this->asAgent();
        $this->postJson("/api/admin/transfers/{$t->id}/release", ['reason' => 'x y z'])->assertForbidden();
    }

    public function test_suspending_an_agent_returns_unstarted_transfers_to_the_queue_and_flags_processing_ones(): void
    {
        $t1 = $this->manualTransfer();
        $agent = $this->asAgent();
        $this->postJson("/api/agent/transfers/{$t1->id}/claim")->assertOk();

        Sanctum::actingAs($this->customer, ['*']);
        $this->postJson('/api/transfer', $this->mmPayload(['amount' => 2000]))->assertOk();
        $t2 = Transaction::latest('id')->firstOrFail();
        $this->asAgent($agent);
        $this->postJson("/api/agent/transfers/{$t2->id}/claim");
        $this->postJson("/api/agent/transfers/{$t2->id}/start");

        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);
        $this->putJson("/api/admin/agents/{$agent->id}", ['status' => false])
            ->assertOk()->assertJsonPath('released_transfers', 1)->assertJsonPath('processing_transfers', 1);

        $this->assertSame('pending_manual_review', $t1->fresh()->status);
        $this->assertNull($t1->fresh()->assigned_agent_id);
        $this->assertSame('processing', $t2->fresh()->status);
    }

    // --- 3. retrait / dépôt et routage ---

    public function test_withdrawals_and_deposits_follow_the_admin_kill_switches(): void
    {
        $provider = Provider::factory()->inactive()->create(); // Digitwave coupé par l'admin

        $this->postJson('/api/withdrawal', $this->withdrawalPayload())->assertStatus(400);
        $this->postJson('/api/deposit', $this->mmPayload())->assertStatus(400);
        $this->assertSame(0, Transaction::count());
        $this->assertEquals(100000, (float) $this->customer->wallet()->first()->balance);

        // Provider réactivé : le retrait passe (montant différent pour ne pas buter sur le verrou de 15 s).
        $provider->update(['active' => true]);
        $this->postJson('/api/withdrawal', array_merge($this->withdrawalPayload(), ['amount' => 1500]))->assertOk();
    }

    public function test_withdrawals_are_refused_when_the_country_service_is_inactive_or_manual(): void
    {
        $config = CountryService::factory()->create(['country_id' => $this->operator->country_id, 'status' => CountryServiceStatus::Inactive]);
        $this->postJson('/api/withdrawal', $this->withdrawalPayload())->assertStatus(400);

        $config->update(['status' => CountryServiceStatus::Manual]);
        $this->postJson('/api/deposit', $this->mmPayload())->assertStatus(400);
        $this->assertSame(0, Transaction::count());
    }

    // --- 5. champs internes ---

    public function test_customer_endpoints_do_not_expose_agent_or_routing_internals(): void
    {
        $t = $this->manualTransfer();
        $this->asAgent();
        $this->postJson("/api/agent/transfers/{$t->id}/claim")->assertOk();

        Sanctum::actingAs($this->customer, ['*']);
        $hidden = ['assigned_agent_id', 'processed_by', 'provider_id', 'provider_reference', 'idempotency_key', 'priority'];

        foreach (['/api/transactions' => 'data.0', '/api/history' => 'data.data.0'] as $url => $path) {
            $row = $this->getJson($url)->assertOk()->json($path);
            $this->assertNotNull($row, $url);
            foreach ($hidden as $field) {
                $this->assertArrayNotHasKey($field, $row, "$url expose $field");
            }
        }
    }

    public function test_the_admin_ledger_still_shows_the_internal_fields(): void
    {
        $t = $this->manualTransfer();
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);

        $row = $this->getJson('/api/admin/transactions')->assertOk()->json('data.0');
        $this->assertArrayHasKey('assigned_agent_id', $row);
        $this->assertArrayHasKey('processing_mode', $row);
        $this->assertSame($t->id, $row['id']);
    }
}
