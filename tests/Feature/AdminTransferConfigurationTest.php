<?php

namespace Tests\Feature;

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

class AdminTransferConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return tap(User::factory()->admin()->create(), fn ($u) => Sanctum::actingAs($u, ['*']));
    }

    private function agent(): User
    {
        return tap(User::factory()->agent()->create(), fn ($u) => Sanctum::actingAs($u, ['*']));
    }

    public function test_admin_manages_providers(): void
    {
        $this->admin();

        $id = $this->postJson('/api/admin/providers', ['code' => 'digitwave', 'name' => 'Digitwave', 'services' => ['MOBILE_MONEY']])
            ->assertCreated()->assertJsonPath('data.implemented', true)->json('data.id');
        $this->postJson('/api/admin/providers', ['code' => 'bankx', 'name' => 'BankX'])->assertCreated()->assertJsonPath('data.implemented', false);
        $this->postJson('/api/admin/providers', ['code' => 'digitwave', 'name' => 'Doublon'])->assertStatus(422);

        $this->putJson("/api/admin/providers/{$id}", ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->getJson('/api/admin/providers')->assertOk()->assertJsonCount(2);
    }

    public function test_agent_and_customer_cannot_manage_configuration(): void
    {
        $country = Country::factory()->create();
        $provider = Provider::factory()->create();
        $service = CountryService::factory()->create(['country_id' => $country->id]);
        $fee = FeeRule::create(['service' => 'MOBILE_MONEY', 'currency' => 'XAF', 'fixed_fee' => 1]);

        foreach ([$this->agent(), User::factory()->create()] as $user) {
            Sanctum::actingAs($user, ['*']);

            $this->getJson('/api/admin/providers')->assertForbidden();
            $this->postJson('/api/admin/providers', ['code' => 'x', 'name' => 'x'])->assertForbidden();
            $this->putJson("/api/admin/providers/{$provider->id}", ['active' => false])->assertForbidden();
            $this->postJson('/api/admin/countries', ['name' => 'Z'])->assertForbidden();
            $this->putJson("/api/admin/countries/{$country->id}", ['status' => false])->assertForbidden();
            $this->postJson('/api/admin/country-services', [])->assertForbidden();
            $this->putJson("/api/admin/country-services/{$service->id}", ['status' => 'INACTIVE'])->assertForbidden();
            $this->postJson('/api/admin/fee-rules', [])->assertForbidden();
            $this->putJson("/api/admin/fee-rules/{$fee->id}", ['fixed_fee' => 0])->assertForbidden();
            $this->putJson("/api/admin/countries/{$country->id}/bank-fields", ['fields' => []])->assertForbidden();
            $this->postJson('/api/admin/agents', [])->assertForbidden();
            $this->getJson('/api/admin/transfers')->assertForbidden();
            $this->getJson('/api/admin/manual-transfers')->assertForbidden();
        }

        $this->assertTrue($provider->fresh()->active);
        $this->assertSame('ACTIVE', $service->fresh()->status->value);
        $this->assertEquals(1, (float) $fee->fresh()->fixed_fee);
    }

    public function test_country_service_configuration_and_validation(): void
    {
        $this->admin();
        $country = Country::factory()->create();
        $mmOnly = Provider::factory()->create();

        $this->postJson('/api/admin/country-services', ['country_id' => $country->id, 'service' => 'BANK_TRANSFER', 'status' => 'ACTIVE', 'provider_id' => $mmOnly->id])
            ->assertStatus(422)->assertJsonValidationErrors('provider_id');

        $id = $this->postJson('/api/admin/country-services', ['country_id' => $country->id, 'service' => 'BANK_TRANSFER', 'status' => 'MANUAL', 'daily_limit' => 500000])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/admin/country-services', ['country_id' => $country->id, 'service' => 'BANK_TRANSFER', 'status' => 'ACTIVE'])->assertStatus(422);
        $this->postJson('/api/admin/country-services', ['country_id' => $country->id, 'service' => 'MOBILE_MONEY', 'status' => 'BOGUS'])->assertStatus(422);

        $this->putJson("/api/admin/country-services/{$id}", ['status' => 'INACTIVE', 'min_amount' => 100, 'max_amount' => 50])->assertStatus(422);
        $this->putJson("/api/admin/country-services/{$id}", ['status' => 'INACTIVE'])->assertOk()->assertJsonPath('data.status', 'INACTIVE');
    }

    public function test_admin_configuration_drives_routing_end_to_end(): void
    {
        Queue::fake();
        $this->admin();
        $country = Country::factory()->create(['name' => 'Gabon', 'iso' => 'GA', 'currency' => 'XAF', 'status' => true]);
        $operator = Operator::factory()->create(['country_id' => $country->id, 'code' => 'AIRTEL_GA', 'min_amount' => 100, 'max_amount' => 100000]);

        // Banque : activée en MANUAL avec des frais et des champs bancaires propres au pays.
        $this->postJson('/api/admin/country-services', ['country_id' => $country->id, 'service' => 'BANK_TRANSFER', 'status' => 'MANUAL'])->assertCreated();
        $this->postJson('/api/admin/fee-rules', ['country_id' => $country->id, 'service' => 'BANK_TRANSFER', 'currency' => 'xaf', 'min_amount' => 0, 'fixed_fee' => 300, 'percent_fee' => 0])->assertCreated()->assertJsonPath('data.currency', 'XAF');
        $this->putJson("/api/admin/countries/{$country->id}/bank-fields", ['fields' => ['full_name' => true, 'iban' => true, 'swift_bic' => true, 'account_number' => false]])
            ->assertOk()->assertJsonPath('required_fields', ['full_name', 'iban', 'swift_bic']);
        $this->putJson("/api/admin/countries/{$country->id}/bank-fields", ['fields' => ['nope' => true]])->assertStatus(422);

        $customer = User::factory()->create(['transaction_pin' => Hash::make('1234')]);
        $customer->wallet()->update(['balance' => 50000]);
        Sanctum::actingAs($customer, ['*']);

        $bank = ['country' => 'GA', 'amount' => 5000, 'pin' => '1234', 'beneficiary' => ['full_name' => 'A B', 'iban' => 'GA2100100000001234567890123', 'swift_bic' => 'ABCDGAXX']];
        $this->postJson('/api/bank-transfer', $bank)->assertOk()->assertJson(['processing_mode' => 'MANUAL', 'fee_charged' => 300.0]);

        // L'admin désactive la banque pour ce pays : le virement suivant est refusé.
        $this->admin();
        $serviceId = CountryService::where('service', 'BANK_TRANSFER')->value('id');
        $this->putJson("/api/admin/country-services/{$serviceId}", ['status' => 'INACTIVE'])->assertOk();
        Sanctum::actingAs($customer, ['*']);
        $this->postJson('/api/bank-transfer', $bank + [], ['Idempotency-Key' => 'other'])->assertStatus(400);

        // Mobile Money : Digitwave désactivé par l'admin → le transfert passe en manuel.
        $this->admin();
        $provider = Provider::factory()->create();
        $this->putJson("/api/admin/providers/{$provider->id}", ['active' => false])->assertOk();
        Sanctum::actingAs($customer, ['*']);
        $this->postJson('/api/transfer', ['country' => 'Gabon', 'carrier' => 'AIRTEL_GA', 'number' => '077000000', 'amount' => 1000, 'pin' => '1234'])
            ->assertOk()->assertJson(['processing_mode' => 'MANUAL']);
    }

    public function test_admin_creates_and_suspends_agents(): void
    {
        $this->admin();

        $id = $this->postJson('/api/admin/agents', ['name' => 'Agent Un', 'phone' => '690000001', 'password' => 'secret123'])
            ->assertCreated()->assertJsonPath('data.status', true)->json('data.id');
        $this->postJson('/api/admin/agents', ['name' => 'Doublon', 'phone' => '690000001', 'password' => 'secret123'])->assertStatus(422);
        $this->assertSame('agent', User::findOrFail($id)->role);

        $agent = User::findOrFail($id);
        $token = $agent->createToken('t')->plainTextToken;
        $this->putJson("/api/admin/agents/{$id}", ['status' => false])->assertOk();

        $this->assertSame(0, $agent->tokens()->count());
        $this->assertFalse($agent->fresh()->status);
        $this->assertNotEmpty($token);

        // L'endpoint de gestion des clients ne touche pas aux agents.
        $this->putJson("/api/admin/users/{$id}", ['status' => true])->assertNotFound();
    }

    public function test_admin_supervises_transfers_with_audit_trail(): void
    {
        Queue::fake();
        $country = Country::factory()->create(['name' => 'Gabon', 'status' => true]);
        $operator = Operator::factory()->create(['country_id' => $country->id, 'code' => 'AIRTEL_GA', 'min_amount' => 100, 'max_amount' => 100000]);
        CountryService::factory()->create(['country_id' => $country->id]);
        $customer = User::factory()->create(['transaction_pin' => Hash::make('1234')]);
        $customer->wallet()->update(['balance' => 10000]);
        Sanctum::actingAs($customer, ['*']);
        $this->postJson('/api/transfer', ['country' => 'Gabon', 'carrier' => $operator->code, 'number' => '077000000', 'amount' => 1000, 'pin' => '1234'])->assertOk();
        $t = Transaction::firstOrFail();

        $this->admin();
        $this->getJson('/api/admin/transfers')->assertOk()->assertJsonPath('data.0.reference', $t->reference);
        $this->getJson('/api/admin/transfers?service=BANK_TRANSFER')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/manual-transfers?status=pending_manual_review')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/admin/transfers/{$t->id}")->assertOk()->assertJsonPath('data.history.0.action', 'TRANSFER_CREATED');
    }
}
