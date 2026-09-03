<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MerchantTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_list_merchants_with_their_wallet(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['*']);

        $merchant = User::factory()->merchant()->create(['company_name' => 'Acme Corp']);
        $merchant->wallet()->update(['balance' => 5000]);

        $response = $this->getJson('/api/admin/merchants');

        $response->assertStatus(200)
            ->assertJsonFragment(['company_name' => 'Acme Corp'])
            ->assertJsonPath('0.wallet.balance', 5000);
    }

    public function test_regular_customers_are_not_listed_as_merchants(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['*']);

        User::factory()->create(); // client mobile money classique (role='customer')
        User::factory()->merchant()->create();

        $response = $this->getJson('/api/admin/merchants');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    public function test_an_admin_can_suspend_a_merchant(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['*']);

        $merchant = User::factory()->merchant()->create(['status' => true]);

        $response = $this->putJson('/api/admin/merchants/'.$merchant->id, ['status' => false]);

        $response->assertStatus(200);
        $this->assertFalse((bool) $merchant->fresh()->status);
    }

    public function test_an_admin_can_switch_a_merchant_environment(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['*']);

        $merchant = User::factory()->merchant()->create(['environment' => 'sandbox']);

        $response = $this->putJson('/api/admin/merchants/'.$merchant->id, ['environment' => 'production']);

        $response->assertStatus(200);
        $this->assertSame('production', $merchant->fresh()->environment);
    }

    public function test_a_standard_user_cannot_access_merchant_routes(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/admin/merchants');

        $response->assertStatus(403);
    }
}
