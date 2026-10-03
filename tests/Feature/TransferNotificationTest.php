<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\CountryService;
use App\Models\Operator;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransferNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private function manualTransfer(): Transaction
    {
        Storage::fake('local');
        $country = Country::factory()->create(['name' => 'Gabon', 'status' => true]);
        $operator = Operator::factory()->create(['country_id' => $country->id, 'code' => 'AIRTEL_GA', 'min_amount' => 100, 'max_amount' => 100000]);
        CountryService::factory()->create(['country_id' => $country->id]);

        $this->customer = User::factory()->create(['transaction_pin' => Hash::make('1234')]);
        $this->customer->wallet()->update(['balance' => 10000]);
        Sanctum::actingAs($this->customer, ['*']);

        $this->postJson('/api/transfer', ['country' => 'Gabon', 'carrier' => $operator->code, 'number' => '077000000', 'amount' => 1000, 'pin' => '1234'])->assertOk();

        return Transaction::firstOrFail();
    }

    private function messages(): array
    {
        return $this->customer->notifications()->oldest()->get()->pluck('data.event')->all();
    }

    public function test_customer_is_notified_at_every_step_of_a_completed_transfer(): void
    {
        $t = $this->manualTransfer();
        Sanctum::actingAs(User::factory()->agent()->create(), ['*']);

        $this->postJson("/api/agent/transfers/{$t->id}/claim")->assertOk();
        $this->postJson("/api/agent/transfers/{$t->id}/start")->assertOk();
        $this->postJson("/api/agent/transfers/{$t->id}/proof", ['proof' => UploadedFile::fake()->create('p.pdf', 10, 'application/pdf')])->assertCreated();
        $this->postJson("/api/agent/transfers/{$t->id}/complete")->assertOk();

        $this->assertSame(['TransferCreated', 'TransferAssigned', 'TransferProcessing', 'TransferCompleted'], $this->messages());
    }

    public function test_customer_is_notified_of_a_rejection_and_a_failure(): void
    {
        $t = $this->manualTransfer();
        Sanctum::actingAs(User::factory()->agent()->create(), ['*']);
        $this->postJson("/api/agent/transfers/{$t->id}/claim");
        $this->postJson("/api/agent/transfers/{$t->id}/start");
        $this->postJson("/api/agent/transfers/{$t->id}/reject", ['reason' => 'Compte invalide'])->assertOk();

        $this->assertSame(['TransferCreated', 'TransferAssigned', 'TransferProcessing', 'TransferRejected'], $this->messages());
    }

    public function test_no_notification_when_a_transition_is_refused(): void
    {
        $t = $this->manualTransfer();
        Sanctum::actingAs(User::factory()->agent()->create(), ['*']);
        $this->postJson("/api/agent/transfers/{$t->id}/claim")->assertOk();
        $this->postJson("/api/agent/transfers/{$t->id}/complete")->assertStatus(409);

        $this->assertSame(['TransferCreated', 'TransferAssigned'], $this->messages());
    }

    public function test_agents_are_told_about_a_new_manual_transfer(): void
    {
        $agent = User::factory()->agent()->create();
        $inactive = User::factory()->agent()->create(['status' => false]);
        $this->manualTransfer();

        $this->assertSame(1, $agent->notifications()->count());
        $this->assertSame('transfer.manual_pending', $agent->notifications()->first()->data['type']);
        $this->assertSame(0, $inactive->notifications()->count());
    }

    public function test_notifications_endpoint_lists_and_marks_as_read(): void
    {
        $this->manualTransfer();

        $response = $this->getJson('/api/notifications')->assertOk()->assertJsonPath('unread_count', 1)->assertJsonPath('data.0.status', 'pending_manual_review');
        $id = $response->json('data.0.id');

        $this->postJson("/api/notifications/{$id}/read")->assertOk();
        $this->getJson('/api/notifications?unread=1')->assertJsonCount(0, 'data');
    }

    public function test_notifications_are_private_to_their_owner(): void
    {
        $this->manualTransfer();
        $id = $this->customer->notifications()->first()->id;

        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->postJson("/api/notifications/{$id}/read")->assertNotFound();
    }

    public function test_sandbox_and_gateway_transfers_are_not_notified(): void
    {
        Queue::fake();
        $transfer = $this->manualTransfer();
        $this->customer->notifications()->delete();

        $transfer->update(['channel' => 'gateway']);
        (new \App\Listeners\NotifyTransferStatusChanged)->handle(new \App\Events\TransferCompleted($transfer));

        $this->assertSame(0, $this->customer->notifications()->count());
    }
}
