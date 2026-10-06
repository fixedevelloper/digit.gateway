<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Operator;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cycle de vie d'un opérateur : statut (« kill-switch »), modification, suppression protégée.
 * Complète OperatorManagementTest (création, frais, unicité par corridor).
 */
class OperatorLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);
    }

    private function transactionFor(Operator $operator, array $overrides = []): Transaction
    {
        return Transaction::create($overrides + [
            'reference' => 'TX-'.uniqid(), 'type' => 'transfer', 'user_id' => User::factory()->create()->id, 'recipient_phone' => '1',
            'recipient_operator' => $operator->code, 'operator_id' => $operator->id, 'country_name' => $operator->country->name,
            'amount_sent' => 1, 'currency_sent' => 'XAF', 'fees' => 0, 'amount_to_receive' => 1, 'currency_received' => 'XAF', 'status' => 'success',
        ]);
    }

    // ------------------------------------------------------------------ Statut

    public function test_an_operator_can_be_cut_and_restored_with_a_plain_put(): void
    {
        $this->admin();
        $operator = Operator::factory()->create(['status' => true]);

        $this->putJson("/api/admin/operators/{$operator->id}", ['status' => false])->assertOk()->assertJsonPath('data.status', false);
        $this->assertFalse($operator->fresh()->status);
        $this->putJson("/api/admin/operators/{$operator->id}", ['status' => true])->assertOk();
        $this->assertTrue($operator->fresh()->status);
    }

    public function test_the_dashboard_form_style_update_works_through_method_spoofing(): void
    {
        Storage::fake('public');
        $this->admin();
        $operator = Operator::factory()->create();

        // Le dashboard envoie un multipart POST + _method=PUT (nécessaire pour téléverser un logo).
        $this->post("/api/admin/operators/{$operator->id}", ['_method' => 'PUT', 'name' => 'Nouveau nom', 'logo' => UploadedFile::fake()->image('l.png')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.name', 'Nouveau nom');

        Storage::disk('public')->assertExists($operator->fresh()->logo);
    }

    public function test_a_plain_post_without_method_spoofing_is_not_an_update(): void
    {
        $this->admin();
        $operator = Operator::factory()->create(['status' => true]);

        // Régression côté dashboard : le bouton « En ligne / Coupé » envoyait un POST JSON simple (405).
        $this->postJson("/api/admin/operators/{$operator->id}", ['status' => false])->assertStatus(405);
        $this->assertTrue($operator->fresh()->status);
    }

    // ------------------------------------------------------------ Modification

    public function test_a_new_logo_replaces_and_deletes_the_previous_one(): void
    {
        Storage::fake('public');
        $this->admin();
        $old = UploadedFile::fake()->image('old.png')->store('logos', 'public');
        $operator = Operator::factory()->create(['logo' => $old]);

        $this->post("/api/admin/operators/{$operator->id}", ['_method' => 'PUT', 'logo' => UploadedFile::fake()->image('new.png')], ['Accept' => 'application/json'])->assertOk();

        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($operator->fresh()->logo);
    }

    public function test_bounds_and_fees_are_validated_on_update(): void
    {
        $this->admin();
        $operator = Operator::factory()->create(['min_amount' => 100, 'max_amount' => 1000]);
        $put = fn (array $body) => $this->putJson("/api/admin/operators/{$operator->id}", $body);

        $put(['min_amount' => 500, 'max_amount' => 400])->assertStatus(422)->assertJsonValidationErrors('max_amount');
        $put(['percent_fee' => 2])->assertStatus(422)->assertJsonValidationErrors('percent_fee');
        $put(['fixed_fee' => -1])->assertStatus(422)->assertJsonValidationErrors('fixed_fee');
        $put(['phone_length' => 0])->assertStatus(422)->assertJsonValidationErrors('phone_length');
        $put(['country_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('country_id');
        $this->putJson('/api/admin/operators/999999', ['name' => 'x'])->assertNotFound();
    }

    public function test_an_unused_operator_can_change_its_code_country_and_currency(): void
    {
        $this->admin();
        $other = Country::factory()->create();
        $operator = Operator::factory()->create(['code' => 'OLD', 'currency' => 'XAF']);

        $this->putJson("/api/admin/operators/{$operator->id}", ['code' => 'NEW', 'country_id' => $other->id, 'currency' => 'usd'])->assertOk();

        $fresh = $operator->fresh();
        $this->assertSame(['NEW', $other->id, 'USD'], [$fresh->code, $fresh->country_id, $fresh->currency]);
    }

    public function test_once_it_has_transactions_its_identity_is_frozen_but_the_rest_stays_editable(): void
    {
        $this->admin();
        $other = Country::factory()->create();
        $operator = Operator::factory()->create(['code' => 'MTN_CM', 'currency' => 'XAF', 'name' => 'MTN']);
        $this->transactionFor($operator);

        foreach ([['code' => 'AUTRE'], ['country_id' => $other->id], ['currency' => 'USD']] as $change) {
            $this->putJson("/api/admin/operators/{$operator->id}", $change)->assertStatus(422)->assertJsonValidationErrors(array_key_first($change));
        }

        $same = ['code' => 'MTN_CM', 'country_id' => $operator->country_id, 'currency' => 'xaf'];
        $this->putJson("/api/admin/operators/{$operator->id}", $same + ['name' => 'MTN Cameroun', 'fixed_fee' => 75, 'status' => false])
            ->assertOk()->assertJsonPath('data.name', 'MTN Cameroun');
        $this->assertSame('MTN_CM', $operator->fresh()->code);
    }

    // ------------------------------------------------------------- Suppression

    public function test_an_unused_operator_is_deleted_with_its_logo_and_pending_quotes(): void
    {
        Storage::fake('public');
        $this->admin();
        $logo = UploadedFile::fake()->image('l.png')->store('logos', 'public');
        $operator = Operator::factory()->create(['logo' => $logo]);
        DB::table('quotes')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => User::factory()->create()->id, 'operator_id' => $operator->id, 'type' => 'transfer',
            'amount' => 1, 'fee' => 0, 'total' => 1, 'currency' => 'XAF', 'converted_amount' => 1, 'converted_fee' => 0, 'converted_currency' => 'XAF',
            'rate' => 1, 'expires_at' => now()->addMinute(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->deleteJson("/api/admin/operators/{$operator->id}")->assertOk()->assertJsonPath('status', 'success');

        $this->assertNull(Operator::find($operator->id));
        Storage::disk('public')->assertMissing($logo);
        $this->assertSame(0, DB::table('quotes')->count());
        $this->deleteJson("/api/admin/operators/{$operator->id}")->assertNotFound();
    }

    public function test_an_operator_with_transactions_cannot_be_deleted(): void
    {
        $this->admin();
        $operator = Operator::factory()->create();
        $this->transactionFor($operator);

        $res = $this->deleteJson("/api/admin/operators/{$operator->id}")->assertStatus(409)->assertJsonPath('error_code', 'OPERATOR_IN_USE');

        $this->assertStringContainsString('1 transaction(s)', $res->json('message'));
        $this->assertNotNull(Operator::find($operator->id));
    }

    public function test_legacy_transactions_matched_by_code_and_country_also_protect_the_operator(): void
    {
        $this->admin();
        $operator = Operator::factory()->create(['code' => 'MTN_CM']);
        // Ancienne transaction, avant l'existence de operator_id.
        $this->transactionFor($operator, ['operator_id' => null]);

        $this->deleteJson("/api/admin/operators/{$operator->id}")->assertStatus(409);

        // Même code dans un autre pays : ne protège pas cet opérateur-là.
        $elsewhere = Operator::factory()->create(['code' => 'MTN_CM']);
        $this->assertSame(0, Transaction::where('operator_id', $elsewhere->id)->count());
        $this->deleteJson("/api/admin/operators/{$elsewhere->id}")->assertOk();
    }

    public function test_the_forced_operator_of_a_country_cannot_be_deleted_until_released(): void
    {
        $this->admin();
        $country = Country::factory()->create();
        $operator = Operator::factory()->create(['country_id' => $country->id]);
        $country->update(['forced_operator_id' => $operator->id]);

        $res = $this->deleteJson("/api/admin/operators/{$operator->id}")->assertStatus(409)->assertJsonPath('error_code', 'OPERATOR_IN_USE');
        $this->assertStringContainsString('1 pays où il est forcé', $res->json('message'));
        $this->assertSame($operator->id, $country->fresh()->forced_operator_id);

        $this->putJson("/api/admin/countries/{$country->id}", ['forced_operator_id' => null])->assertOk();
        $this->deleteJson("/api/admin/operators/{$operator->id}")->assertOk();
    }

    public function test_cutting_is_the_alternative_when_deletion_is_refused(): void
    {
        $this->admin();
        $operator = Operator::factory()->create(['status' => true]);
        $this->transactionFor($operator);

        $this->deleteJson("/api/admin/operators/{$operator->id}")->assertStatus(409);
        $this->putJson("/api/admin/operators/{$operator->id}", ['status' => false])->assertOk();
        $this->assertFalse($operator->fresh()->status);
    }

    // ------------------------------------------------------------------ Droits

    public function test_only_admins_can_manage_operators(): void
    {
        $operator = Operator::factory()->create(['name' => 'Intact']);

        foreach ([User::factory()->create(), User::factory()->agent()->create(), User::factory()->merchant()->create()] as $user) {
            Sanctum::actingAs($user, ['*']);

            $this->getJson('/api/admin/operators')->assertForbidden();
            $this->putJson("/api/admin/operators/{$operator->id}", ['name' => 'Hack'])->assertForbidden();
            $this->deleteJson("/api/admin/operators/{$operator->id}")->assertForbidden();
        }

        $this->assertSame('Intact', $operator->fresh()->name);
    }
}
