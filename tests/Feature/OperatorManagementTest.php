<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Operator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperatorManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);
    }

    public function test_creating_an_operator_persists_the_fees(): void
    {
        $this->actingAsAdmin();
        $country = Country::factory()->create();

        $response = $this->postJson('/api/admin/operators', [
            'name' => 'MTN Mobile Money',
            'code' => 'MTN_CM',
            'country_id' => $country->id,
            'phone_length' => 9,
            'fixed_fee' => 100,
            'percent_fee' => 0.015,
            'min_amount' => 100,
            'max_amount' => 500000,
        ]);

        $response->assertStatus(201);

        // Régression : fixed_fee/percent_fee étaient absents du $fillable du modèle
        // Operator et se retrouvaient silencieusement ignorés malgré une réponse "success".
        $this->assertDatabaseHas('operators', [
            'code' => 'MTN_CM',
            'fixed_fee' => 100,
            'percent_fee' => 0.015,
        ]);
    }

    public function test_updating_an_operator_persists_the_fees(): void
    {
        $this->actingAsAdmin();
        $operator = Operator::factory()->create(['fixed_fee' => 0, 'percent_fee' => 0]);

        $response = $this->putJson('/api/admin/operators/'.$operator->id, [
            'fixed_fee' => 250,
            'percent_fee' => 0.02,
        ]);

        $response->assertStatus(200);
        $this->assertSame('250.00', $operator->fresh()->fixed_fee);
        $this->assertSame('0.0200', $operator->fresh()->percent_fee);
    }

    public function test_an_svg_logo_is_accepted_on_create_and_update(): void
    {
        $this->actingAsAdmin();
        $country = Country::factory()->create();

        // Régression : la règle de validation 'image' rejette catégoriquement les SVG
        // (protection XSS de Laravel), même quand 'mimes' les autorise explicitement.
        $svg = UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml');

        $response = $this->post('/api/admin/operators', [
            'name' => 'Orange Money',
            'code' => 'ORANGE_CM',
            'country_id' => $country->id,
            'phone_length' => 9,
            'fixed_fee' => 50,
            'percent_fee' => 0.01,
            'min_amount' => 100,
            'max_amount' => 500000,
            'logo' => $svg,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201);
        $operator = Operator::where('code', 'ORANGE_CM')->firstOrFail();
        $this->assertNotNull($operator->logo);

        $newSvg = UploadedFile::fake()->create('new-logo.svg', 10, 'image/svg+xml');

        $updateResponse = $this->post('/api/admin/operators/'.$operator->id, [
            '_method' => 'PUT',
            'logo' => $newSvg,
        ], ['Accept' => 'application/json']);

        $updateResponse->assertStatus(200);
    }
}
