<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_logs_in_and_token_only_opens_agent_routes(): void
    {
        $agent = User::factory()->agent()->create(['phone' => '690000009']);

        $token = $this->postJson('/api/agent/auth/login', ['phone' => '690000009', 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.role', 'agent')->json('token');

        $this->withToken($token)->getJson('/api/agent/transfers')->assertOk();
        $this->withToken($token)->getJson('/api/admin/providers')->assertForbidden();
        $this->withToken($token)->postJson('/api/agent/auth/logout')->assertOk();
        $this->assertSame(0, $agent->tokens()->count());
    }

    public function test_wrong_password_customer_admin_and_suspended_agent_are_refused(): void
    {
        User::factory()->agent()->create(['phone' => '690000009']);
        User::factory()->admin()->create(['phone' => '690000010']);
        User::factory()->agent()->create(['phone' => '690000011', 'status' => false]);

        $this->postJson('/api/agent/auth/login', ['phone' => '690000009', 'password' => 'bad'])->assertStatus(422);
        $this->postJson('/api/agent/auth/login', ['phone' => '690000010', 'password' => 'password'])->assertStatus(422);
        $this->postJson('/api/agent/auth/login', ['phone' => '690000011', 'password' => 'password'])->assertStatus(403);
    }

    public function test_agent_cannot_use_the_admin_login(): void
    {
        User::factory()->agent()->create(['phone' => '690000009']);

        $this->postJson('/api/admin/auth/login', ['phone' => '690000009', 'password' => 'password'])->assertStatus(403);
    }
}
