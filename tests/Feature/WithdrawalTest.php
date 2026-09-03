<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Country;
use App\Models\Operator;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private function setUpUserAndOperator(float $balance): User
    {
        $user = User::factory()->create([
            'transaction_pin' => Hash::make('1234'),
        ]);
        $user->wallet()->update(['balance' => $balance]);

        $country = Country::factory()->create(['name' => 'Cameroon', 'status' => true]);
        Operator::factory()->create([
            'country_id' => $country->id,
            'code' => 'MTN_CM',
            'status' => true,
            'fixed_fee' => 50,
            'percent_fee' => 0.01,
            'min_amount' => 100,
            'max_amount' => 100000,
        ]);

        return $user->fresh();
    }

    public function test_withdrawal_is_rejected_with_an_invalid_agency_code(): void
    {
        Queue::fake();
        $user = $this->setUpUserAndOperator(10000);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/withdrawal', [
            'country' => 'Cameroon',
            'agensic_code' => 'DOES-NOT-EXIST',
            'carrier' => 'MTN_CM',
            'number' => '677000000',
            'amount' => 1000,
            'pin' => '1234',
        ]);

        $response->assertStatus(422);
        $this->assertSame(10000.0, (float) $user->wallet->fresh()->balance);
    }

    public function test_withdrawal_succeeds_with_a_valid_agency_and_debits_the_wallet(): void
    {
        Queue::fake();
        $user = $this->setUpUserAndOperator(10000);
        $agency = Agency::factory()->create(['code' => 'AG-001', 'status' => 'active']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/withdrawal', [
            'country' => 'Cameroon',
            'agensic_code' => 'AG-001',
            'carrier' => 'MTN_CM',
            'number' => '677000000',
            'amount' => 1000,
            'pin' => '1234',
        ]);

        // fee = 50 + 1000 * 0.01 = 60
        $response->assertStatus(200)->assertJsonPath('status', 'success');
        $this->assertSame(10000 - 1060.0, (float) $user->wallet->fresh()->balance);

        // Régression : 'agency_id' était absent du $fillable du modèle Transaction et se
        // retrouvait silencieusement ignoré malgré la réponse "success" — toute demande de
        // retrait perdait son lien vers l'agence qui l'avait traitée.
        $this->assertSame($agency->id, Transaction::where('reference', $response->json('request_id'))->value('agency_id'));
    }

    public function test_withdrawal_with_a_suspended_agency_is_rejected(): void
    {
        Queue::fake();
        $user = $this->setUpUserAndOperator(10000);
        Agency::factory()->create(['code' => 'AG-002', 'status' => 'inactive']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/withdrawal', [
            'country' => 'Cameroon',
            'agensic_code' => 'AG-002',
            'carrier' => 'MTN_CM',
            'number' => '677000000',
            'amount' => 1000,
            'pin' => '1234',
        ]);

        $response->assertStatus(422);
    }
}
