<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletAdjustment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_credit_adjustment_updates_balance_and_leaves_an_audit_trail(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['*']);

        $target = User::factory()->create();
        $target->wallet()->update(['balance' => 1000]);

        $response = $this->postJson('/api/admin/wallets/'.$target->wallet->id.'/adjust', [
            'type' => 'credit',
            'amount' => 500,
            'reason' => 'Régularisation manuelle après incident agrégateur',
        ]);

        $response->assertStatus(200);
        $this->assertSame(1500.0, (float) $target->wallet->fresh()->balance);

        $this->assertDatabaseHas('wallet_adjustments', [
            'wallet_id' => $target->wallet->id,
            'admin_id' => $admin->id,
            'type' => 'credit',
            'amount' => 500,
            'balance_before' => 1000,
            'balance_after' => 1500,
        ]);
    }

    public function test_a_debit_adjustment_with_insufficient_balance_is_rejected_and_not_logged(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['*']);

        $target = User::factory()->create();
        $target->wallet()->update(['balance' => 100]);

        $response = $this->postJson('/api/admin/wallets/'.$target->wallet->id.'/adjust', [
            'type' => 'debit',
            'amount' => 500,
            'reason' => 'Tentative de débit trop élevé',
        ]);

        $response->assertStatus(422);
        $this->assertSame(100.0, (float) $target->wallet->fresh()->balance);
        $this->assertSame(0, WalletAdjustment::count());
    }

    public function test_the_adjustment_history_endpoint_lists_past_adjustments(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['*']);

        $target = User::factory()->create();
        $target->wallet()->update(['balance' => 1000]);

        $this->postJson('/api/admin/wallets/'.$target->wallet->id.'/adjust', [
            'type' => 'debit',
            'amount' => 200,
            'reason' => 'Correction comptable',
        ])->assertStatus(200);

        $response = $this->getJson('/api/admin/wallets/'.$target->wallet->id.'/adjustments');

        $response->assertStatus(200)
            ->assertJsonPath('data.data.0.reason', 'Correction comptable')
            ->assertJsonPath('data.data.0.admin.id', $admin->id);
    }
}
