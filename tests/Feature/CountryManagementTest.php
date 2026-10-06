<?php

namespace Tests\Feature;

use App\Models\BankBeneficiary;
use App\Models\Country;
use App\Models\CountryService;
use App\Models\FeeRule;
use App\Models\Operator;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CountryManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);
    }

    private function body(array $overrides = []): array
    {
        return array_merge(['name' => 'Ghana', 'iso' => 'GH', 'iso3' => 'GHA', 'phonecode' => '233', 'currency' => 'GHS'], $overrides);
    }

    // ---------------------------------------------------------------- Création

    public function test_a_country_is_created_active_with_normalised_codes_and_a_flag(): void
    {
        Storage::fake('public');
        $this->admin();

        $res = $this->post('/api/admin/countries', $this->body(['iso' => 'gh', 'iso3' => 'gha', 'currency' => 'ghs', 'flag' => UploadedFile::fake()->image('gh.png')]), ['Accept' => 'application/json'])
            ->assertCreated();

        $country = Country::firstWhere('name', 'Ghana');
        $this->assertSame(['GH', 'GHA', 'GHS'], [$country->iso, $country->iso3, $country->currency]);
        $this->assertTrue($country->status);
        Storage::disk('public')->assertExists($country->flag);
        $this->assertNotNull($res->json('data.flag_url'));
    }

    #[DataProvider('invalidCreations')]
    public function test_creation_validation(array $override, string $field): void
    {
        $this->admin();
        Country::factory()->create(['name' => 'Cameroon', 'iso' => 'CM', 'iso3' => 'CMR']);

        $this->postJson('/api/admin/countries', $this->body($override))->assertStatus(422)->assertJsonValidationErrors($field);
    }

    public static function invalidCreations(): array
    {
        return [
            'nom manquant' => [['name' => ''], 'name'],
            'nom déjà pris' => [['name' => 'Cameroon'], 'name'],
            'iso trop court' => [['iso' => 'G'], 'iso'],
            'iso trop long' => [['iso' => 'GHA'], 'iso'],
            'iso déjà pris (casse ignorée)' => [['iso' => 'cm'], 'iso'],
            'iso3 trop court' => [['iso3' => 'GH'], 'iso3'],
            'iso3 déjà pris (casse ignorée)' => [['iso3' => 'cmr'], 'iso3'],
            'indicatif manquant' => [['phonecode' => ''], 'phonecode'],
            'devise manquante' => [['currency' => ''], 'currency'],
            'drapeau non image' => [['flag' => UploadedFile::fake()->create('x.exe', 10, 'application/octet-stream')], 'flag'],
        ];
    }

    // ------------------------------------------------------------ Modification

    public function test_a_country_can_be_edited_and_its_old_flag_is_removed(): void
    {
        Storage::fake('public');
        $this->admin();
        $old = UploadedFile::fake()->image('old.png')->store('flags', 'public');
        $country = Country::factory()->create(['name' => 'Ghana', 'iso' => 'GH', 'iso3' => 'GHA', 'flag' => $old]);

        $this->put("/api/admin/countries/{$country->id}", ['name' => 'Ghana (GH)', 'iso' => 'gh', 'currency' => 'ghs', 'flag' => UploadedFile::fake()->image('new.png')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.name', 'Ghana (GH)');

        $fresh = $country->fresh();
        $this->assertSame(['GH', 'GHS'], [$fresh->iso, $fresh->currency]);
        $this->assertNotSame($old, $fresh->flag);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($fresh->flag);
    }

    public function test_editing_keeps_a_countrys_own_codes_valid_but_refuses_another_countrys(): void
    {
        $this->admin();
        Country::factory()->create(['name' => 'Cameroon', 'iso' => 'CM', 'iso3' => 'CMR']);
        $ghana = Country::factory()->create(['name' => 'Ghana', 'iso' => 'GH', 'iso3' => 'GHA']);

        $this->putJson("/api/admin/countries/{$ghana->id}", ['iso' => 'gh', 'iso3' => 'GHA'])->assertOk();
        $this->putJson("/api/admin/countries/{$ghana->id}", ['iso' => 'cm'])->assertStatus(422)->assertJsonValidationErrors('iso');
        $this->putJson('/api/admin/countries/999999', ['name' => 'x'])->assertNotFound();
    }

    public function test_the_forced_operator_must_belong_to_the_country_and_can_be_cleared(): void
    {
        $this->admin();
        $ghana = Country::factory()->create();
        $other = Country::factory()->create();
        $mine = Operator::factory()->create(['country_id' => $ghana->id]);
        $foreign = Operator::factory()->create(['country_id' => $other->id]);

        $this->putJson("/api/admin/countries/{$ghana->id}", ['forced_operator_id' => $foreign->id])->assertStatus(422);
        $this->putJson("/api/admin/countries/{$ghana->id}", ['forced_operator_id' => $mine->id])->assertOk()->assertJsonPath('data.forced_operator_id', $mine->id);
        $this->putJson("/api/admin/countries/{$ghana->id}", ['forced_operator_id' => null])->assertOk();
        $this->assertNull($ghana->fresh()->forced_operator_id);
    }

    public function test_suspending_a_country_is_reversible(): void
    {
        $this->admin();
        $country = Country::factory()->create(['status' => true]);

        $this->putJson("/api/admin/countries/{$country->id}", ['status' => false])->assertOk();
        $this->assertFalse($country->fresh()->status);
        $this->putJson("/api/admin/countries/{$country->id}", ['status' => true])->assertOk();
        $this->assertTrue($country->fresh()->status);
    }

    // ------------------------------------------------------------- Suppression

    public function test_an_unused_country_is_deleted_with_its_flag_and_bank_field_rules(): void
    {
        Storage::fake('public');
        $this->admin();
        $flag = UploadedFile::fake()->image('f.png')->store('flags', 'public');
        $country = Country::factory()->create(['flag' => $flag]);
        DB::table('bank_field_rules')->insert(['country_id' => $country->id, 'field' => 'iban', 'required' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->deleteJson("/api/admin/countries/{$country->id}")->assertOk()->assertJsonPath('status', 'success');

        $this->assertNull(Country::find($country->id));
        Storage::disk('public')->assertMissing($flag);
        $this->assertSame(0, DB::table('bank_field_rules')->where('country_id', $country->id)->count());
        $this->deleteJson("/api/admin/countries/{$country->id}")->assertNotFound();
    }

    public static function references(): array
    {
        $transaction = fn (array $o) => Transaction::create($o + [
            'reference' => 'TX-'.uniqid(), 'type' => 'transfer', 'user_id' => User::factory()->create()->id, 'recipient_phone' => '1',
            'recipient_operator' => 'X', 'country_name' => 'Ailleurs', 'amount_sent' => 1, 'currency_sent' => 'XAF', 'fees' => 0,
            'amount_to_receive' => 1, 'currency_received' => 'XAF', 'status' => 'success',
        ]);

        return [
            'opérateur actif' => [fn (Country $c) => Operator::factory()->create(['country_id' => $c->id, 'status' => true]), '1 opérateur(s)'],
            'opérateur coupé' => [fn (Country $c) => Operator::factory()->create(['country_id' => $c->id, 'status' => false]), '1 opérateur(s)'],
            'service configuré' => [fn (Country $c) => CountryService::factory()->create(['country_id' => $c->id]), '1 service(s) configuré(s)'],
            'règle de frais' => [fn (Country $c) => FeeRule::create(['country_id' => $c->id, 'service' => 'BANK_TRANSFER', 'currency' => 'XAF', 'fixed_fee' => 1]), '1 règle(s) de frais'],
            'bénéficiaire bancaire' => [fn (Country $c) => BankBeneficiary::create(['user_id' => User::factory()->create()->id, 'country_id' => $c->id, 'full_name' => 'A B']), '1 bénéficiaire(s) bancaire(s)'],
            'transaction (pays de destination)' => [fn (Country $c) => $transaction(['destination_country_id' => $c->id]), '1 transaction(s)'],
            'transaction (nom du pays)' => [fn (Country $c) => $transaction(['country_name' => $c->name]), '1 transaction(s)'],
        ];
    }

    #[DataProvider('references')]
    public function test_a_country_still_in_use_cannot_be_deleted(callable $reference, string $expected): void
    {
        $this->admin();
        $country = Country::factory()->create(['name' => 'Ghana']);
        $reference($country);

        $res = $this->deleteJson("/api/admin/countries/{$country->id}")->assertStatus(409)->assertJsonPath('error_code', 'COUNTRY_IN_USE');

        $this->assertStringContainsString($expected, $res->json('message'));
        $this->assertNotNull(Country::find($country->id));
    }

    public function test_a_refused_deletion_cascades_nothing(): void
    {
        $this->admin();
        $country = Country::factory()->create();
        $operator = Operator::factory()->create(['country_id' => $country->id]);
        $service = CountryService::factory()->create(['country_id' => $country->id]);

        $this->deleteJson("/api/admin/countries/{$country->id}")->assertStatus(409);

        $this->assertNotNull(Operator::find($operator->id));
        $this->assertNotNull(CountryService::find($service->id));
    }

    // ------------------------------------------------------------------ Droits

    public function test_only_admins_can_manage_countries(): void
    {
        $country = Country::factory()->create(['name' => 'Ghana']);

        foreach ([User::factory()->create(), User::factory()->agent()->create(), User::factory()->merchant()->create()] as $user) {
            Sanctum::actingAs($user, ['*']);

            $this->getJson('/api/admin/countries')->assertForbidden();
            $this->postJson('/api/admin/countries', $this->body(['name' => 'Togo']))->assertForbidden();
            $this->putJson("/api/admin/countries/{$country->id}", ['name' => 'Hack'])->assertForbidden();
            $this->deleteJson("/api/admin/countries/{$country->id}")->assertForbidden();
        }

        $this->assertSame('Ghana', $country->fresh()->name);
        $this->assertNull(Country::firstWhere('name', 'Togo'));
    }
}
