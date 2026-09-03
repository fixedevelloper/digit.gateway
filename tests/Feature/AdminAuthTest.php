<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_succeeds_for_an_admin_user(): void
    {
        User::factory()->admin()->create([
            'phone' => '677000010',
            'password' => bcrypt('adminpass'),
        ]);

        $response = $this->postJson('/api/admin/auth/login', [
            'phone' => '677000010',
            'password' => 'adminpass',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 'success');
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_admin_login_rejects_a_standard_customer(): void
    {
        User::factory()->create([
            'role' => 'customer',
            'phone' => '677000011',
            'password' => bcrypt('customerpass'),
        ]);

        $response = $this->postJson('/api/admin/auth/login', [
            'phone' => '677000011',
            'password' => 'customerpass',
        ]);

        $response->assertStatus(403);
    }

    public function test_legacy_auth_login_route_no_longer_exists(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'phone' => '677000012',
            'password' => 'whatever',
        ]);

        $response->assertStatus(404);
    }
}
