<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\KycLimit;
use App\Models\KycSubmission;
use App\Models\Operator;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\KycReviewedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KycTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();

        $country = Country::factory()->create(['name' => 'Cameroon', 'status' => true]);
        Operator::factory()->create([
            'country_id' => $country->id, 'code' => 'MTN_CM', 'status' => true,
            'fixed_fee' => 0, 'percent_fee' => 0, 'min_amount' => 100, 'max_amount' => 100000,
        ]);
    }

    private function customer(int $level = 1, float $balance = 100000): User
    {
        $user = User::factory()->create(['transaction_pin' => Hash::make('1234')]);
        $user->forceFill(['kyc_level' => $level])->save();
        $user->wallet()->update(['balance' => $balance]);

        return $user->fresh();
    }

    private function transfer(float $amount)
    {
        return $this->postJson('/api/transfer', [
            'country' => 'Cameroon', 'carrier' => 'MTN_CM', 'number' => '677000000',
            'amount' => $amount, 'apikey' => 'x', 'pin' => '1234',
        ]);
    }

    // ----------------------------------------------------------------- Plafonds

    public function test_per_transaction_limit_blocks_and_does_not_debit(): void
    {
        KycLimit::find(1)->update(['per_transaction' => 1000, 'daily_limit' => null, 'monthly_limit' => null]);
        $user = $this->customer();
        Sanctum::actingAs($user, ['*']);

        $this->transfer(1500)->assertStatus(400)->assertSee('plafond par transaction')
            ->assertJsonPath('error_code', 'KYC_LIMIT_PER_TRANSACTION');

        $this->assertSame(0, Transaction::count());
        $this->assertSame(100000.0, (float) $user->wallet->fresh()->balance);
    }

    public function test_daily_limit_counts_previous_transfers_but_not_failed_ones(): void
    {
        KycLimit::find(1)->update(['per_transaction' => null, 'daily_limit' => 2500, 'monthly_limit' => null]);
        $user = $this->customer();
        Sanctum::actingAs($user, ['*']);

        // Montants distincts : le garde anti-doublon (15 s) bloque deux requêtes identiques.
        $this->transfer(1000)->assertOk();
        $this->transfer(1001)->assertOk();
        $this->transfer(1002)->assertStatus(400)->assertSee('journalier');

        // Un transfert échoué libère le plafond.
        Transaction::first()->update(['status' => 'failed']);
        $this->transfer(1003)->assertOk();
    }

    public function test_higher_level_has_higher_limits_and_merchants_are_exempt(): void
    {
        KycLimit::find(1)->update(['per_transaction' => 1000]);
        KycLimit::find(2)->update(['per_transaction' => 5000]);

        Sanctum::actingAs($this->customer(2), ['*']);
        $this->transfer(3000)->assertOk();

        $merchant = User::factory()->merchant()->create(['transaction_pin' => Hash::make('1234')]);
        $merchant->wallet()->update(['balance' => 100000]);
        $merchant->forceFill(['kyc_level' => 1])->save();
        Sanctum::actingAs($merchant->fresh(), ['*']);
        $this->transfer(3000)->assertOk();
    }

    // ---------------------------------------------------------------------- KYC

    private function submit(User $user, array $overrides = [])
    {
        Sanctum::actingAs($user, ['*']);

        return $this->postJson('/api/kyc/submissions', array_merge([
            'target_level' => 2,
            'document_type' => 'national_id',
            'document_number' => 'CM123456',
            'full_name' => 'Jean Dupont',
            'birth_date' => '1990-04-12',
            'front' => UploadedFile::fake()->image('front.jpg'),
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
        ], $overrides));
    }

    public function test_customer_submits_documents_stored_privately_and_summary_shows_it(): void
    {
        $user = $this->customer();

        $res = $this->submit($user)->assertCreated()->assertJsonMissingPath('data.files');

        $sub = KycSubmission::findOrFail($res->json('data.id'));
        $this->assertSame('pending', $sub->status);
        foreach ($sub->files as $file) {
            Storage::disk('local')->assertExists($file['path']);
        }

        $this->getJson('/api/kyc')->assertOk()
            ->assertJsonPath('data.level', 1)
            ->assertJsonPath('data.next_level', 2)
            ->assertJsonPath('data.latest_submission.status', 'pending');
    }

    public function test_only_one_pending_request_and_only_next_level(): void
    {
        $user = $this->customer();

        $this->submit($user)->assertCreated();
        $this->submit($user)->assertStatus(422);
        $this->submit($this->customer(), ['target_level' => 3, 'document_type' => 'proof_of_address', 'proof' => UploadedFile::fake()->image('p.jpg')])
            ->assertStatus(422);
    }

    public function test_admin_approval_raises_level_notifies_and_cannot_be_replayed(): void
    {
        $user = $this->customer();
        $id = $this->submit($user)->json('data.id');

        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);
        $this->getJson("/api/admin/kyc/submissions/{$id}")->assertOk()->assertJsonPath('data.files.0.role', 'front');
        $this->get("/api/admin/kyc/submissions/{$id}/files/0")->assertOk();

        $this->postJson("/api/admin/kyc/submissions/{$id}/approve")->assertOk();
        $this->assertSame(2, $user->fresh()->kyc_level);
        $this->assertSame(1, $user->notifications()->where('type', KycReviewedNotification::class)->count());

        $this->postJson("/api/admin/kyc/submissions/{$id}/approve")->assertStatus(409);
        $this->postJson("/api/admin/kyc/submissions/{$id}/reject", ['reason' => 'trop tard'])->assertStatus(409);
    }

    public function test_rejection_keeps_level_and_allows_a_new_request(): void
    {
        $user = $this->customer();
        $id = $this->submit($user)->json('data.id');

        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);
        $this->postJson("/api/admin/kyc/submissions/{$id}/reject", [])->assertStatus(422);
        $this->postJson("/api/admin/kyc/submissions/{$id}/reject", ['reason' => 'Photo floue'])->assertOk();

        $this->assertSame(1, $user->fresh()->kyc_level);
        $this->submit($user->fresh())->assertCreated();
    }

    public function test_kyc_documents_are_not_reachable_by_customers_and_level_is_not_mass_assignable(): void
    {
        $owner = $this->customer();
        $id = $this->submit($owner)->json('data.id');

        Sanctum::actingAs($this->customer(), ['*']);
        $this->get("/api/admin/kyc/submissions/{$id}/files/0")->assertForbidden();

        $this->postJson('/api/profile/update', ['name' => 'X', 'kyc_level' => 3]);
        $this->assertSame(1, $owner->fresh()->kyc_level);
    }

    public function test_only_superadmin_edits_limits_and_they_must_not_decrease(): void
    {
        $payload = fn (int $l2) => ['limits' => [
            ['level' => 1, 'per_transaction' => 1000, 'daily_limit' => 2000, 'monthly_limit' => 5000],
            ['level' => 2, 'per_transaction' => $l2, 'daily_limit' => 4000, 'monthly_limit' => 9000],
            ['level' => 3, 'per_transaction' => null, 'daily_limit' => null, 'monthly_limit' => null],
        ]];

        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);
        $this->putJson('/api/admin/kyc/limits', $payload(5000))->assertForbidden();

        Sanctum::actingAs(User::factory()->superadmin()->create(), ['*']);
        $this->putJson('/api/admin/kyc/limits', $payload(500))->assertStatus(422);
        $this->putJson('/api/admin/kyc/limits', $payload(5000))->assertOk();
        $this->assertSame('5000.00', KycLimit::find(2)->per_transaction);
    }
}
