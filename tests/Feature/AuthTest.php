<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_requires_a_transaction_pin(): void
    {
        $response = $this->postJson('/api/register', [
            'phone' => '677000001',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('pin');
    }

    public function test_register_creates_exactly_one_wallet(): void
    {
        $response = $this->postJson('/api/register', [
            'phone' => '677000002',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'pin' => '1234',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 'success');

        $user = User::where('phone', '677000002')->firstOrFail();

        $this->assertSame(1, Wallet::where('user_id', $user->id)->count());
        $this->assertSame(0.0, (float) $user->wallet->balance);
    }

    public function test_login_with_valid_credentials_returns_a_token(): void
    {
        User::factory()->create([
            'phone' => '677000003',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->postJson('/api/login', [
            'phone' => '677000003',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 'success');
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_with_invalid_password_is_rejected(): void
    {
        User::factory()->create([
            'phone' => '677000004',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->postJson('/api/login', [
            'phone' => '677000004',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
    }
}
