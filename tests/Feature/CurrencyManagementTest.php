<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\FeeRule;
use App\Models\Operator;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CurrencyManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);
    }

    private function ghs(): Currency
    {
        return Currency::create(['code' => 'GHS', 'name' => 'Cedi ghanéen', 'symbol' => 'GH₵']);
    }

    public function test_currencies_are_listed_by_code(): void
    {
        $this->admin();
        $this->ghs();

        $codes = collect($this->getJson('/api/admin/currencies')->assertOk()->json())->pluck('code')->all();

        $this->assertSame($codes, collect($codes)->sort()->values()->all());
        $this->assertContains('GHS', $codes);
        $this->assertContains('XAF', $codes);
    }

    public function test_a_currency_is_created_with_an_uppercase_code(): void
    {
        $this->admin();

        $this->postJson('/api/admin/currencies', ['code' => 'ghs', 'name' => 'Cedi', 'symbol' => 'GH₵'])
            ->assertCreated()->assertJsonPath('data.code', 'GHS');
    }

    #[DataProvider('invalidCreations')]
    public function test_creation_validation(array $body, string $field): void
    {
        $this->admin();

        $this->postJson('/api/admin/currencies', $body)->assertStatus(422)->assertJsonValidationErrors($field);
    }

    public static function invalidCreations(): array
    {
        $ok = ['code' => 'GHS', 'name' => 'Cedi', 'symbol' => 'GH₵'];

        return [
            'code trop court' => [['code' => 'GH'] + $ok, 'code'],
            'code trop long' => [['code' => 'GHSS'] + $ok, 'code'],
            'code avec chiffre' => [['code' => 'G1S'] + $ok, 'code'],
            'code déjà pris (casse ignorée)' => [['code' => 'xaf'] + $ok, 'code'],
            'nom manquant' => [['name' => ''] + $ok, 'name'],
            'symbole manquant' => [['symbol' => ''] + $ok, 'symbol'],
            'symbole trop long' => [['symbol' => str_repeat('x', 11)] + $ok, 'symbol'],
        ];
    }

    public function test_name_and_symbol_can_be_edited_but_never_the_code(): void
    {
        $this->admin();
        $ghs = $this->ghs();

        $this->putJson("/api/admin/currencies/{$ghs->id}", ['name' => 'Cedi (Ghana)', 'symbol' => 'GHS', 'code' => 'ZZZ'])
            ->assertOk()->assertJsonPath('data.name', 'Cedi (Ghana)')->assertJsonPath('data.code', 'GHS');

        $this->assertSame('GHS', $ghs->fresh()->code);
        $this->putJson("/api/admin/currencies/{$ghs->id}", ['name' => ''])->assertStatus(422);
        $this->putJson('/api/admin/currencies/999999', ['name' => 'x'])->assertNotFound();
    }

    public function test_an_unused_currency_can_be_deleted(): void
    {
        $this->admin();
        $ghs = $this->ghs();

        $this->deleteJson("/api/admin/currencies/{$ghs->id}")->assertOk()->assertJsonPath('status', 'success');
        $this->assertNull(Currency::find($ghs->id));
        $this->deleteJson("/api/admin/currencies/{$ghs->id}")->assertNotFound();
    }

    /** @return array<string, array{0: callable(): void, 1: string}> */
    public static function references(): array
    {
        return [
            'taux de change (base)' => [fn () => ExchangeRate::create(['base_currency' => 'GHS', 'quote_currency' => 'XAF', 'rate' => 50]), '1 taux de change'],
            'taux de change (cotée)' => [fn () => ExchangeRate::create(['base_currency' => 'USD', 'quote_currency' => 'GHS', 'rate' => 15]), '1 taux de change'],
            'opérateur' => [fn () => Operator::factory()->create(['currency' => 'GHS']), '1 opérateur(s)'],
            'pays' => [fn () => Country::factory()->create(['currency' => 'GHS']), '1 pays'],
            'wallet' => [fn () => User::factory()->create()->wallet()->update(['currency' => 'GHS']), '1 wallet(s)'],
            'règle de frais' => [fn () => FeeRule::create(['service' => 'MOBILE_MONEY', 'currency' => 'GHS', 'fixed_fee' => 1]), '1 règle(s) de frais'],
            'transaction (reçue)' => [function () {
                Transaction::create([
                    'reference' => 'TX-C', 'type' => 'transfer', 'user_id' => User::factory()->create()->id, 'recipient_phone' => '1',
                    'recipient_operator' => 'X', 'country_name' => 'X', 'amount_sent' => 1, 'currency_sent' => 'XAF', 'fees' => 0,
                    'amount_to_receive' => 1, 'currency_received' => 'GHS', 'status' => 'success',
                ]);
            }, '1 transaction(s)'],
            'cotation' => [function () {
                $operator = Operator::factory()->create(['currency' => 'XAF']);
                DB::table('quotes')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => User::factory()->create()->id, 'operator_id' => $operator->id,
                    'type' => 'transfer', 'amount' => 1, 'fee' => 0, 'total' => 1, 'currency' => 'XAF', 'converted_amount' => 1,
                    'converted_fee' => 0, 'converted_currency' => 'GHS', 'rate' => 1, 'expires_at' => now()->addMinute(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }, '1 cotation(s)'],
        ];
    }

    #[DataProvider('references')]
    public function test_a_currency_still_in_use_cannot_be_deleted(callable $reference, string $expected): void
    {
        $this->admin();
        $ghs = $this->ghs();
        $reference();

        $res = $this->deleteJson("/api/admin/currencies/{$ghs->id}")->assertStatus(409)->assertJsonPath('error_code', 'CURRENCY_IN_USE');

        $this->assertStringContainsString($expected, $res->json('message'));
        $this->assertNotNull(Currency::find($ghs->id));
    }

    public function test_the_wallet_currency_xaf_cannot_be_deleted_once_accounts_exist(): void
    {
        $this->admin();

        $xaf = Currency::where('code', 'XAF')->firstOrFail();

        $this->deleteJson("/api/admin/currencies/{$xaf->id}")->assertStatus(409)->assertSee('wallet');
        $this->assertNotNull(Currency::find($xaf->id));
    }

    public function test_only_admins_can_manage_currencies(): void
    {
        $ghs = $this->ghs();

        foreach ([User::factory()->create(), User::factory()->agent()->create(), User::factory()->merchant()->create()] as $user) {
            Sanctum::actingAs($user, ['*']);

            $this->getJson('/api/admin/currencies')->assertForbidden();
            $this->postJson('/api/admin/currencies', ['code' => 'AAA', 'name' => 'a', 'symbol' => 'a'])->assertForbidden();
            $this->putJson("/api/admin/currencies/{$ghs->id}", ['name' => 'x'])->assertForbidden();
            $this->deleteJson("/api/admin/currencies/{$ghs->id}")->assertForbidden();
        }

        $this->assertNotNull(Currency::find($ghs->id));
        $this->assertSame('Cedi ghanéen', $ghs->fresh()->name);
    }
}
