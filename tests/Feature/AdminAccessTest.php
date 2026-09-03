<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_standard_user_cannot_access_admin_routes(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/admin/wallets');

        $response->assertStatus(403);
    }

    public function test_a_standard_user_cannot_adjust_a_wallet(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/admin/wallets/'.$user->wallet->id.'/adjust', [
            'type' => 'credit',
            'amount' => 1000,
            'reason' => 'tentative non autorisée',
        ]);

        $response->assertStatus(403);
        $this->assertSame(0.0, (float) $user->wallet->fresh()->balance);
    }

    public function test_an_admin_user_can_access_admin_routes(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/admin/wallets');

        $response->assertStatus(200);
    }

    public function test_an_unauthenticated_request_cannot_access_admin_routes(): void
    {
        $response = $this->getJson('/api/admin/wallets');

        $response->assertStatus(401);
    }
}
