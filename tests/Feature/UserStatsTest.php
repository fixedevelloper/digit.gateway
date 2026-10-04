<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserStatsTest extends TestCase
{
    use RefreshDatabase;

    private function tx(User $user, string $type, string $status, float $amount, string $operator, $createdAt = null): void
    {
        $t = Transaction::create([
            'reference' => 'TX-STATS'.uniqid(),
            'type' => $type,
            'user_id' => $user->id,
            'recipient_phone' => '677000000',
            'recipient_operator' => $operator,
            'country_name' => 'Cameroon',
            'amount_sent' => $amount,
            'currency_sent' => 'XAF',
            'fees' => 0,
            'amount_to_receive' => $amount,
            'currency_received' => 'XAF',
            'status' => $status,
        ]);

        if ($createdAt) {
            $t->forceFill(['created_at' => $createdAt])->save();
        }
    }

    public function test_stats_aggregate_the_authenticated_users_own_transactions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->tx($user, 'deposit', 'success', 5000, 'ORANGE');
        $this->tx($user, 'transfer', 'success', 2000, 'ORANGE');
        $this->tx($user, 'withdrawal', 'success', 1000, 'MTN');
        $this->tx($user, 'transfer', 'failed', 9999, 'MTN');
        $this->tx($user, 'transfer', 'processing', 7777, 'MTN'); // ni réussi ni échoué : ignoré
        $this->tx($other, 'deposit', 'success', 123456, 'ORANGE'); // autre utilisateur : ignoré
        $this->tx($user, 'deposit', 'success', 4000, 'ORANGE', now()->subYears(2)); // hors période

        $this->getJson('/api/stats?period=month')
            ->assertOk()
            ->assertJsonPath('data.inflow', 5000)
            ->assertJsonPath('data.outflow', 3000)
            ->assertJsonPath('data.success_rate', 75) // 3 réussies / (3 + 1 échouée)
            ->assertJsonPath('data.operators.0', ['operator' => 'ORANGE', 'volume' => 7000])
            ->assertJsonPath('data.operators.1', ['operator' => 'MTN', 'volume' => 1000]);
    }

    public function test_stats_are_empty_without_transactions_and_require_authentication(): void
    {
        $this->getJson('/api/stats')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->getJson('/api/stats?period=week')
            ->assertOk()
            ->assertJsonPath('data.inflow', 0)
            ->assertJsonPath('data.success_rate', 0)
            ->assertJsonPath('data.operators', []);
    }
}
