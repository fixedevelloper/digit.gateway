<?php

namespace Tests\Feature;

use App\Jobs\ProcessTransferJob;
use App\Models\Country;
use App\Models\Operator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransferTest extends TestCase
{
    use RefreshDatabase;

    private function userWithBalance(float $balance, string $pin = '1234'): User
    {
        $user = User::factory()->create([
            'transaction_pin' => Hash::make($pin),
        ]);

        $user->wallet()->update(['balance' => $balance]);

        return $user->fresh();
    }

    private function activeOperator(string $country = 'Cameroon', string $code = 'MTN_CM'): Operator
    {
        $countryModel = Country::factory()->create(['name' => $country, 'status' => true]);

        return Operator::factory()->create([
            'country_id' => $countryModel->id,
            'code' => $code,
            'status' => true,
            'fixed_fee' => 50,
            'percent_fee' => 0.01,
            'min_amount' => 100,
            'max_amount' => 100000,
        ]);
    }

    public function test_transfer_is_rejected_with_a_wrong_pin(): void
    {
        Queue::fake();
        $user = $this->userWithBalance(10000);
        $this->activeOperator();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/transfer', [
            'country' => 'Cameroon',
            'carrier' => 'MTN_CM',
            'number' => '677000000',
            'amount' => 1000,
            'apikey' => 'x',
            'pin' => '0000',
        ]);

        $response->assertStatus(403);
        Queue::assertNotPushed(ProcessTransferJob::class);
    }

    public function test_transfer_is_rejected_for_an_unknown_operator(): void
    {
        Queue::fake();
        $user = $this->userWithBalance(10000);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/transfer', [
            'country' => 'Cameroon',
            'carrier' => 'UNKNOWN_OP',
            'number' => '677000000',
            'amount' => 1000,
            'apikey' => 'x',
            'pin' => '1234',
        ]);

        $response->assertStatus(400);
    }

    public function test_transfer_is_rejected_with_insufficient_balance(): void
    {
        Queue::fake();
        $user = $this->userWithBalance(100);
        $this->activeOperator();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/transfer', [
            'country' => 'Cameroon',
            'carrier' => 'MTN_CM',
            'number' => '677000000',
            'amount' => 1000,
            'apikey' => 'x',
            'pin' => '1234',
        ]);

        $response->assertStatus(400);
        $this->assertSame(100.0, (float) $user->wallet->fresh()->balance);
    }

    public function test_transfer_succeeds_and_debits_the_real_operator_fee(): void
    {
        Queue::fake();
        $user = $this->userWithBalance(10000);
        $this->activeOperator();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/transfer', [
            'country' => 'Cameroon',
            'carrier' => 'MTN_CM',
            'number' => '677000000',
            'amount' => 1000,
            'apikey' => 'x',
            'pin' => '1234',
        ]);

        // fee = fixed_fee(50) + amount(1000) * percent_fee(0.01) = 60
        $response->assertStatus(200)->assertJsonPath('status', 'success');
        $this->assertSame(60.0, (float) $response->json('fee_charged'));

        $this->assertSame(10000 - 1060.0, (float) $user->wallet->fresh()->balance);
        Queue::assertPushed(ProcessTransferJob::class);
    }

    public function test_duplicate_transfer_submission_within_a_few_seconds_is_rejected(): void
    {
        Queue::fake();
        $user = $this->userWithBalance(10000);
        $this->activeOperator();
        Sanctum::actingAs($user, ['*']);

        $payload = [
            'country' => 'Cameroon',
            'carrier' => 'MTN_CM',
            'number' => '677000000',
            'amount' => 1000,
            'apikey' => 'x',
            'pin' => '1234',
        ];

        $first = $this->postJson('/api/transfer', $payload);
        $second = $this->postJson('/api/transfer', $payload);

        $first->assertStatus(200);
        $second->assertStatus(409);

        // Un seul débit doit avoir eu lieu.
        $this->assertSame(10000 - 1060.0, (float) $user->wallet->fresh()->balance);
    }
}
