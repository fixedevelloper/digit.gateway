<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\CountryService;
use App\Models\Operator;
use App\Models\Transaction;
use App\Models\TransferAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManualTransferWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
    }

    /** Crée un transfert Mobile Money manuel par le vrai parcours client (1000 + 60 de frais débités). */
    private function manualTransfer(): Transaction
    {
        $country = Country::factory()->create(['name' => 'Gabon', 'status' => true]);
        $operator = Operator::factory()->create(['country_id' => $country->id, 'code' => 'AIRTEL_GA', 'fixed_fee' => 50, 'percent_fee' => 0.01, 'min_amount' => 100, 'max_amount' => 100000]);
        CountryService::factory()->create(['country_id' => $country->id]);

        $this->customer = User::factory()->create(['transaction_pin' => Hash::make('1234')]);
        $this->customer->wallet()->update(['balance' => 10000]);
        Sanctum::actingAs($this->customer, ['*']);

        $this->postJson('/api/transfer', ['country' => 'Gabon', 'carrier' => $operator->code, 'number' => '077000000', 'amount' => 1000, 'pin' => '1234'])->assertOk();

        return Transaction::firstOrFail();
    }

    private function balance(): float
    {
        return (float) $this->customer->wallet()->first()->balance;
    }

    private function asAgent(?User $agent = null): User
    {
        $agent ??= User::factory()->agent()->create();
        Sanctum::actingAs($agent, ['*']);

        return $agent;
    }

    private function toProcessing(Transaction $t, User $agent): void
    {
        $this->postJson("/api/agent/transfers/{$t->id}/claim")->assertOk();
        $this->postJson("/api/agent/transfers/{$t->id}/start")->assertOk();
    }

    private function proof(Transaction $t)
    {
        return $this->postJson("/api/agent/transfers/{$t->id}/proof", ['proof' => UploadedFile::fake()->create('preuve.pdf', 100, 'application/pdf')]);
    }

    public function test_queue_lists_pending_manual_transfers(): void
    {
        $t = $this->manualTransfer();
        $this->asAgent();

        $this->getJson('/api/agent/transfers')
            ->assertOk()
            ->assertJsonPath('data.0.reference', $t->reference)
            ->assertJsonPath('data.0.status', 'pending_manual_review')
            ->assertJsonPath('data.0.beneficiary.phone', '077000000');
    }

    public function test_full_agent_flow_completes_the_transfer(): void
    {
        $t = $this->manualTransfer();
        $agent = $this->asAgent();

        $this->postJson("/api/agent/transfers/{$t->id}/claim")->assertOk()->assertJsonPath('data.status', 'assigned')->assertJsonPath('data.assigned_agent_id', $agent->id);
        $this->postJson("/api/agent/transfers/{$t->id}/start")->assertOk()->assertJsonPath('data.status', 'processing');
        $this->proof($t)->assertCreated();
        $this->postJson("/api/agent/transfers/{$t->id}/complete", ['provider_reference' => 'MM-42', 'transaction_reference' => 'TRX-9', 'comment' => 'Payé'])
            ->assertOk()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.provider_reference', 'MM-42');

        $t->refresh();
        $this->assertSame($agent->id, $t->processed_by);
        $this->assertNotNull($t->processed_at);
        $this->assertEquals(10000 - 1060, $this->balance()); // fonds consommés, pas de remboursement
        $this->assertSame(
            ['TRANSFER_CREATED', 'TRANSFER_ASSIGNED', 'TRANSFER_STARTED', 'PROOF_UPLOADED', 'TRANSFER_COMPLETED'],
            $t->auditLogs()->orderBy('id')->pluck('action')->all()
        );
        Storage::disk('local')->assertExists($t->proofs()->first()->file_path);
    }

    public function test_complete_requires_a_proof(): void
    {
        $t = $this->manualTransfer();
        $agent = $this->asAgent();
        $this->toProcessing($t, $agent);

        $this->postJson("/api/agent/transfers/{$t->id}/complete")->assertStatus(409)->assertJsonPath('error_code', 'PROOF_REQUIRED');
        $this->assertSame('processing', $t->fresh()->status);
    }

    public function test_reject_refunds_the_customer_once(): void
    {
        $t = $this->manualTransfer();
        $agent = $this->asAgent();
        $this->toProcessing($t, $agent);

        $this->postJson("/api/agent/transfers/{$t->id}/reject", ['reason' => 'Bénéficiaire invalide'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Bénéficiaire invalide');
        $this->assertEquals(10000, $this->balance());

        // Rejouer ne rembourse pas une seconde fois, et plus aucune transition n'est possible.
        $this->postJson("/api/agent/transfers/{$t->id}/reject", ['reason' => 'Bénéficiaire invalide'])->assertStatus(409);
        $this->postJson("/api/agent/transfers/{$t->id}/fail", ['reason' => 'Encore'])->assertStatus(409);
        $this->assertEquals(10000, $this->balance());
    }

    public function test_fail_refunds_the_customer(): void
    {
        $t = $this->manualTransfer();
        $agent = $this->asAgent();
        $this->toProcessing($t, $agent);

        $this->postJson("/api/agent/transfers/{$t->id}/fail", ['reason' => 'Opérateur indisponible'])
            ->assertOk()->assertJsonPath('data.status', 'failed');

        $this->assertEquals(10000, $this->balance());
        $this->assertSame('Opérateur indisponible', $t->fresh()->failure_reason);
        $this->assertDatabaseHas('transfer_audit_logs', ['transfer_id' => $t->id, 'action' => 'TRANSFER_FAILED', 'role' => 'agent']);
    }

    public function test_reject_and_fail_require_a_reason(): void
    {
        $t = $this->manualTransfer();
        $agent = $this->asAgent();
        $this->toProcessing($t, $agent);

        $this->postJson("/api/agent/transfers/{$t->id}/reject")->assertStatus(422);
        $this->postJson("/api/agent/transfers/{$t->id}/fail")->assertStatus(422);
    }

    public function test_a_transfer_cannot_be_claimed_twice_or_processed_by_another_agent(): void
    {
        $t = $this->manualTransfer();
        $first = $this->asAgent();
        $this->postJson("/api/agent/transfers/{$t->id}/claim")->assertOk();

        $this->asAgent();
        $this->postJson("/api/agent/transfers/{$t->id}/claim")->assertStatus(409);
        $this->postJson("/api/agent/transfers/{$t->id}/start")->assertForbidden();
        $this->assertSame($first->id, $t->fresh()->assigned_agent_id);
    }

    public function test_states_cannot_be_skipped(): void
    {
        $t = $this->manualTransfer();
        $this->asAgent();
        $this->postJson("/api/agent/transfers/{$t->id}/claim")->assertOk();

        $this->postJson("/api/agent/transfers/{$t->id}/complete")->assertStatus(409);
        $this->postJson("/api/agent/transfers/{$t->id}/reject", ['reason' => 'abc'])->assertStatus(409);
    }

    public function test_proof_rejects_other_file_types(): void
    {
        $t = $this->manualTransfer();
        $agent = $this->asAgent();
        $this->toProcessing($t, $agent);

        $this->postJson("/api/agent/transfers/{$t->id}/proof", ['proof' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])->assertStatus(422);
    }

    public function test_customer_can_cancel_before_claim_and_is_refunded(): void
    {
        $t = $this->manualTransfer();

        $this->postJson("/api/transfers/{$t->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertEquals(10000, $this->balance());

        $this->postJson("/api/transfers/{$t->id}/cancel")->assertStatus(409);
        $this->assertEquals(10000, $this->balance());
    }

    public function test_customer_cannot_cancel_once_an_agent_took_it(): void
    {
        $t = $this->manualTransfer();
        $customer = $this->customer;
        $this->asAgent();
        $this->postJson("/api/agent/transfers/{$t->id}/claim")->assertOk();

        Sanctum::actingAs($customer, ['*']);
        $this->postJson("/api/transfers/{$t->id}/cancel")->assertStatus(409);
        $this->assertEquals(10000 - 1060, $this->balance());
    }

    public function test_customer_lists_and_reads_only_own_transfers(): void
    {
        $t = $this->manualTransfer();
        $this->getJson('/api/transfers')->assertOk()->assertJsonPath('data.0.reference', $t->reference)->assertJsonMissingPath('data.0.assigned_agent');
        $this->getJson("/api/transfers/{$t->id}")->assertOk();

        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->getJson("/api/transfers/{$t->id}")->assertForbidden();
        $this->postJson("/api/transfers/{$t->id}/cancel")->assertForbidden();
        $this->getJson('/api/transfers')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_customer_cannot_use_agent_endpoints(): void
    {
        $t = $this->manualTransfer();

        foreach (['claim', 'start', 'complete', 'reject', 'fail', 'proof'] as $action) {
            $this->postJson("/api/agent/transfers/{$t->id}/{$action}")->assertForbidden();
        }
        $this->getJson('/api/agent/transfers')->assertForbidden();
        $this->assertSame('pending_manual_review', $t->fresh()->status);
    }

    public function test_admin_cannot_process_transfers_and_agent_cannot_reach_admin_routes(): void
    {
        $t = $this->manualTransfer();

        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);
        $this->postJson("/api/agent/transfers/{$t->id}/claim")->assertForbidden();

        $this->asAgent();
        $this->getJson('/api/admin/countries')->assertForbidden();
        $this->postJson('/api/admin/countries', [])->assertForbidden();
        $this->getJson('/api/admin/operators')->assertForbidden();
        $this->putJson('/api/admin/users/1', [])->assertForbidden();
    }

    public function test_agent_cannot_modify_or_delete_the_audit_log(): void
    {
        $t = $this->manualTransfer();
        $log = $t->auditLogs()->firstOrFail();

        $this->expectException(\LogicException::class);
        $log->update(['comment' => 'altéré']);
    }

    public function test_audit_log_cannot_be_deleted(): void
    {
        $t = $this->manualTransfer();

        $this->expectException(\LogicException::class);
        TransferAuditLog::where('transfer_id', $t->id)->firstOrFail()->delete();
    }

    public function test_agent_cannot_see_a_non_manual_transfer(): void
    {
        $t = $this->manualTransfer();
        $t->update(['processing_mode' => 'AUTOMATIC']);
        $this->asAgent();

        $this->getJson("/api/agent/transfers/{$t->id}")->assertForbidden();
        $this->postJson("/api/agent/transfers/{$t->id}/claim")->assertForbidden();
    }
}
