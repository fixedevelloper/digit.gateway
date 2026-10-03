<?php

namespace Tests\Feature;

use App\Enums\CountryServiceStatus;
use App\Enums\TransferService;
use App\Models\BankFieldRule;
use App\Models\Country;
use App\Models\CountryService;
use App\Models\FeeRule;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BankTransferTest extends TestCase
{
    use RefreshDatabase;

    private function bankCountry(string $status = 'ACTIVE', string $name = 'Senegal'): Country
    {
        $country = Country::factory()->create(['name' => $name, 'iso' => 'SN', 'currency' => 'XAF', 'status' => true]);
        CountryService::factory()->create([
            'country_id' => $country->id,
            'service' => TransferService::BankTransfer,
            'status' => CountryServiceStatus::from($status),
        ]);
        FeeRule::create(['service' => 'BANK_TRANSFER', 'currency' => 'XAF', 'min_amount' => 0, 'max_amount' => 100000, 'fixed_fee' => 500, 'percent_fee' => 0.01]);

        return $country;
    }

    private function sender(float $balance = 100000): User
    {
        $user = User::factory()->create(['transaction_pin' => Hash::make('1234')]);
        $user->wallet()->update(['balance' => $balance]);
        Sanctum::actingAs($user, ['*']);

        return $user->fresh();
    }

    private function payload(array $beneficiary = [], array $extra = []): array
    {
        return array_merge([
            'country' => 'SN',
            'amount' => 10000,
            'pin' => '1234',
            'beneficiary' => array_merge(['full_name' => 'Awa Diop', 'bank_name' => 'CBAO', 'account_number' => '0123456789'], $beneficiary),
        ], $extra);
    }

    public function test_active_country_creates_a_manual_bank_transfer_and_reserves_funds(): void
    {
        $this->bankCountry();
        $user = $this->sender();

        $this->postJson('/api/bank-transfer', $this->payload())
            ->assertOk()
            ->assertJson(['processing_mode' => 'MANUAL', 'transfer_status' => 'pending_manual_review', 'fee_charged' => 600.0]);

        $transaction = Transaction::firstOrFail();
        $this->assertSame(TransferService::BankTransfer, $transaction->service);
        $this->assertSame('0123456789', $transaction->bankBeneficiary->account_number);
        $this->assertEquals(100000 - 10600, (float) $user->wallet()->first()->balance);
        $this->assertDatabaseHas('transfer_audit_logs', ['transfer_id' => $transaction->id, 'action' => 'TRANSFER_CREATED']);
    }

    public function test_manual_status_country_is_accepted(): void
    {
        $this->bankCountry('MANUAL');
        $this->sender();

        $this->postJson('/api/bank-transfer', $this->payload())->assertOk()->assertJson(['processing_mode' => 'MANUAL']);
    }

    public function test_inactive_or_unconfigured_country_is_rejected_without_debit(): void
    {
        $this->bankCountry('INACTIVE');
        Country::factory()->create(['name' => 'Gabon', 'iso' => 'GA', 'status' => true]);
        $user = $this->sender();

        $this->postJson('/api/bank-transfer', $this->payload())->assertStatus(400);
        $this->postJson('/api/bank-transfer', $this->payload([], ['country' => 'GA']))->assertStatus(400);

        $this->assertDatabaseCount('transactions', 0);
        $this->assertEquals(100000, (float) $user->wallet()->first()->balance);
    }

    public function test_deactivated_country_is_rejected(): void
    {
        $country = $this->bankCountry();
        $country->update(['status' => false]);
        $this->sender();

        $this->postJson('/api/bank-transfer', $this->payload())->assertStatus(400);
    }

    public function test_iban_is_not_required_by_default_but_can_be_required_per_country(): void
    {
        $country = $this->bankCountry();
        $this->sender();

        $this->postJson('/api/bank-transfer', $this->payload())->assertOk();

        foreach (['full_name', 'iban', 'swift_bic'] as $field) {
            BankFieldRule::create(['country_id' => $country->id, 'field' => $field, 'required' => true]);
        }

        $this->postJson('/api/bank-transfer', $this->payload([], ['amount' => 5000]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['beneficiary.iban', 'beneficiary.swift_bic']);

        $this->postJson('/api/bank-transfer', $this->payload(['iban' => 'FR7630006000011234567890189', 'swift_bic' => 'AGRIFRPP'], ['amount' => 5000]))->assertOk();
    }

    public function test_requirements_endpoint_lists_required_fields(): void
    {
        $this->bankCountry();
        $this->sender();

        $this->getJson('/api/bank-transfer/requirements?country=SN')
            ->assertOk()
            ->assertJsonPath('required_fields', ['full_name', 'bank_name', 'account_number']);
    }

    public function test_missing_fee_rule_blocks_the_transfer(): void
    {
        $this->bankCountry();
        FeeRule::query()->delete();
        $user = $this->sender();

        $this->postJson('/api/bank-transfer', $this->payload())->assertStatus(400);
        $this->assertEquals(100000, (float) $user->wallet()->first()->balance);
    }

    public function test_country_specific_fee_rule_wins_over_the_generic_one(): void
    {
        $country = $this->bankCountry();
        FeeRule::create(['country_id' => $country->id, 'service' => 'BANK_TRANSFER', 'currency' => 'XAF', 'min_amount' => 0, 'fixed_fee' => 100, 'percent_fee' => 0]);
        $this->sender();

        $this->postJson('/api/bank-transfer', $this->payload())->assertOk()->assertJson(['fee_charged' => 100.0]);
    }

    public function test_insufficient_funds_is_refused(): void
    {
        $this->bankCountry();
        $this->sender(5000);

        $this->postJson('/api/bank-transfer', $this->payload())->assertStatus(400);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_same_idempotency_key_creates_one_bank_transfer(): void
    {
        $this->bankCountry();
        $user = $this->sender();

        $a = $this->postJson('/api/bank-transfer', $this->payload(), ['Idempotency-Key' => 'bt-1'])->assertOk();
        $b = $this->postJson('/api/bank-transfer', $this->payload(), ['Idempotency-Key' => 'bt-1'])->assertOk();

        $this->assertSame($a->json('request_id'), $b->json('request_id'));
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('bank_beneficiaries', 1);
        $this->assertEquals(100000 - 10600, (float) $user->wallet()->first()->balance);
    }

    public function test_countries_endpoint_lists_only_countries_with_bank_transfer_enabled(): void
    {
        $this->bankCountry('ACTIVE', 'Senegal');
        $manual = Country::factory()->create(['name' => 'Gabon', 'iso' => 'GA', 'currency' => 'XAF', 'status' => true]);
        CountryService::factory()->create(['country_id' => $manual->id, 'service' => TransferService::BankTransfer, 'status' => CountryServiceStatus::Manual]);
        $off = Country::factory()->create(['name' => 'Chad', 'status' => true]);
        CountryService::factory()->create(['country_id' => $off->id, 'service' => TransferService::BankTransfer, 'status' => CountryServiceStatus::Inactive]);
        Country::factory()->create(['name' => 'Mali', 'status' => true]); // aucune configuration
        $this->sender();

        $this->getJson('/api/bank-transfer/countries')
            ->assertOk()
            ->assertJsonCount(2, 'countries')
            ->assertJsonPath('countries.0.name', 'Gabon')
            ->assertJsonPath('countries.1.iso', 'SN')
            ->assertJsonPath('countries.1.required_fields', ['full_name', 'bank_name', 'account_number']);
    }
}
