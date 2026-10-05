<?php

namespace Tests\Feature;

use App\Jobs\ProcessTransferJob;
use App\Jobs\ProcessWithdrawalJob;
use App\Models\Agency;
use App\Models\Country;
use App\Models\ExchangeRate;
use App\Models\Operator;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Contrat de l'API marchande : pays, solde, cotations, transferts, retraits, dépôts, historique.
 * Montants : 1000 XAF → frais 50 + 1 % = 60, total débité 1060.
 */
class MerchantGatewayTransactionsTest extends MerchantApiTestCase
{
    private const TRANSACTION_KEYS = [
        'reference', 'environment', 'type', 'service', 'processing_mode', 'status', 'amount', 'fee', 'currency',
        'amount_received', 'currency_received', 'exchange_rate', 'recipient', 'failure_reason', 'created_at', 'updated_at',
    ];

    private function live(float $balance = 100000): array
    {
        $merchant = $this->merchant('production', $balance);
        [, $plain] = $this->apiKey($merchant);

        return [$merchant, $plain];
    }

    // ----------------------------------------------------------------- Catalogue

    public function test_countries_lists_only_active_countries_and_operators_with_their_conditions(): void
    {
        [, $plain] = $this->live();
        Country::factory()->create(['name' => 'Atlantis', 'iso' => 'AT', 'status' => false]);
        Operator::factory()->create(['country_id' => $this->country->id, 'code' => 'OFF_CM', 'status' => false]);

        $res = $this->getJson('/api/v1/gateway/countries', $this->headers($plain))->assertOk();

        $res->assertJsonCount(1, 'data')->assertJsonPath('data.0.iso', 'CM')->assertJsonCount(1, 'data.0.carriers');
        // assertEquals : le JSON encode 100.0 en 100.
        $this->assertEquals([
            'id' => $this->operator->id, 'code' => 'MTN_CM', 'name' => 'MTN Cameroun', 'currency' => 'XAF',
            'min_amount' => 100, 'max_amount' => 100000, 'fixed_fee' => 50, 'percent_fee' => 0.01,
        ], $res->json('data.0.carriers.0'));
    }

    public function test_country_detail_accepts_any_case_and_unknown_iso_is_a_404(): void
    {
        [, $plain] = $this->live();

        $this->getJson('/api/v1/gateway/countries/cm', $this->headers($plain))->assertOk()->assertJsonPath('data.name', 'Cameroon');
        $this->getJson('/api/v1/gateway/countries/ZZ', $this->headers($plain))
            ->assertStatus(404)->assertJsonPath('error_code', 'COUNTRY_NOT_FOUND');
    }

    // ----------------------------------------------------------------- Cotation

    public function test_a_quote_converts_to_the_operator_currency_and_is_usable_once(): void
    {
        Queue::fake();
        $usd = Operator::factory()->create([
            'country_id' => $this->country->id, 'code' => 'USD_OP', 'currency' => 'USD', 'status' => true,
            'fixed_fee' => 0.5, 'percent_fee' => 0.01, 'min_amount' => 1, 'max_amount' => 1000,
        ]);
        ExchangeRate::create(['base_currency' => 'USD', 'quote_currency' => 'XAF', 'rate' => 600]);
        [$merchant, $plain] = $this->live();

        $quote = $this->postJson('/api/v1/gateway/quotes', [
            'type' => 'transfer', 'country' => 'CM', 'carrier' => 'USD_OP', 'amount' => 10000,
        ], $this->headers($plain))->assertCreated();

        $quote->assertJsonPath('data.currency', 'XAF')->assertJsonPath('data.converted_currency', 'USD')
            ->assertJsonPath('data.operator.id', $usd->id)->assertJsonStructure(['data' => ['quote_id', 'amount', 'fee', 'total', 'rate', 'expires_at']]);
        $quoteId = $quote->json('data.quote_id');

        $body = ['operator_id' => $usd->id, 'number' => '677000002', 'amount' => 10000, 'quote_id' => $quoteId];

        $this->postJson('/api/v1/gateway/transfers', $body, $this->headers($plain, 'q1'))->assertOk();
        $this->postJson('/api/v1/gateway/transfers', $body, $this->headers($plain, 'q2'))
            ->assertStatus(422)->assertJsonPath('error_code', 'QUOTE_ALREADY_USED');
        $this->assertSame(1, Transaction::count());
    }

    public function test_a_cross_currency_transfer_without_a_quote_is_refused_and_nothing_is_debited(): void
    {
        Queue::fake();
        $usd = Operator::factory()->create([
            'country_id' => $this->country->id, 'code' => 'USD_OP', 'currency' => 'USD', 'status' => true,
            'fixed_fee' => 0.5, 'percent_fee' => 0.01, 'min_amount' => 1, 'max_amount' => 1000,
        ]);
        ExchangeRate::create(['base_currency' => 'USD', 'quote_currency' => 'XAF', 'rate' => 600]);
        [$merchant, $plain] = $this->live();

        $this->postJson('/api/v1/gateway/transfers', ['operator_id' => $usd->id, 'number' => '677000002', 'amount' => 10000], $this->headers($plain, 'nq'))
            ->assertStatus(422);

        $this->assertSame(0, Transaction::count());
        $this->assertSame(100000.0, (float) $merchant->wallet->fresh()->balance);
    }

    // ----------------------------------------------------------------- Transferts

    public function test_a_live_transfer_returns_the_documented_contract_and_queues_the_gateway_job(): void
    {
        Queue::fake();
        [$merchant, $plain] = $this->live();

        $res = $this->postJson('/api/v1/gateway/transfers', $this->transferBody(), $this->headers($plain, 'k1'))->assertOk();

        $res->assertJsonPath('status', 'success')->assertJsonPath('message', 'Request accepted, processing in progress');
        $data = $res->json('data');

        $this->assertEqualsCanonicalizing(array_merge(self::TRANSACTION_KEYS, ['total_debited', 'remaining_balance']), array_keys($data));
        $this->assertSame('transfer', $data['type']);
        // Un transfert est débité puis envoyé : il est « processing » dès sa création.
        $this->assertSame('processing', $data['status']);
        $this->assertSame('production', $data['environment']);
        $this->assertSame('MOBILE_MONEY', $data['service']);
        $this->assertSame('AUTOMATIC', $data['processing_mode']);
        $this->assertEquals(1000, $data['amount']);
        $this->assertEquals(60, $data['fee']);
        $this->assertEquals(1060, $data['total_debited']);
        $this->assertEquals(98940, $data['remaining_balance']);
        $this->assertSame(['phone' => '677000001', 'operator' => 'MTN_CM', 'country' => 'Cameroon'], $data['recipient']);
        $this->assertStringStartsWith('TX-', $data['reference']);

        $this->assertSame(98940.0, (float) $merchant->wallet->fresh()->balance);
        $transaction = Transaction::firstOrFail();
        $this->assertSame('merchant_api', $transaction->channel);
        Queue::assertPushed(ProcessTransferJob::class, 1);
    }

    public function test_the_country_is_stored_by_canonical_name_whatever_the_caller_sent(): void
    {
        Queue::fake();
        [, $plain] = $this->live();

        // Régression : « CM » était stocké tel quel puis envoyé à Digitwave à la place de « Cameroon ».
        foreach (['CM' => 'a', 'cm' => 'b', 'Cameroon' => 'c'] as $input => $key) {
            $this->postJson('/api/v1/gateway/transfers', $this->transferBody(['country' => $input, 'amount' => 1000 + ord($key)]), $this->headers($plain, $key))
                ->assertOk()->assertJsonPath('data.recipient.country', 'Cameroon');
        }

        $this->assertSame(['Cameroon'], Transaction::pluck('country_name')->unique()->values()->all());
    }

    public function test_the_response_never_leaks_internal_fields(): void
    {
        Queue::fake();
        [, $plain] = $this->live();

        $data = $this->postJson('/api/v1/gateway/transfers', $this->transferBody(), $this->headers($plain, 'k1'))->json('data');

        foreach (['id', 'user_id', 'assigned_agent_id', 'processed_by', 'provider_id', 'provider_reference', 'idempotency_key', 'gateway_reference', 'priority', 'agency_id'] as $internal) {
            $this->assertArrayNotHasKey($internal, $data, "{$internal} ne doit pas être exposé");
        }
    }

    public function test_validation_errors_are_json_even_without_an_accept_header(): void
    {
        [, $plain] = $this->live();

        $this->call('POST', '/api/v1/gateway/transfers', [], [], [], [
            'HTTP_AUTHORIZATION' => "Bearer {$plain}", 'HTTP_IDEMPOTENCY_KEY' => 'v1',
        ])->assertStatus(422)->assertJsonValidationErrors(['number', 'amount', 'country', 'carrier']);
    }

    public function test_input_validation_rules(): void
    {
        [, $plain] = $this->live();
        $post = fn (array $body) => $this->postJson('/api/v1/gateway/transfers', $body, $this->headers($plain, uniqid()));

        $post($this->transferBody(['amount' => 0]))->assertStatus(422)->assertJsonValidationErrors('amount');
        $post($this->transferBody(['amount' => 'abc']))->assertStatus(422)->assertJsonValidationErrors('amount');
        $post($this->transferBody(['number' => '']))->assertStatus(422)->assertJsonValidationErrors('number');
        $post($this->transferBody(['currency' => 'EURO']))->assertStatus(422)->assertJsonValidationErrors('currency');
        $post($this->transferBody(['quote_id' => 'pas-un-uuid']))->assertStatus(422)->assertJsonValidationErrors('quote_id');
    }

    public function test_business_errors_carry_a_machine_readable_code_and_never_debit(): void
    {
        Queue::fake();
        [$merchant, $plain] = $this->live(500);
        $post = fn (array $body) => $this->postJson('/api/v1/gateway/transfers', $body, $this->headers($plain, uniqid()));

        $post($this->transferBody(['amount' => 1000]))->assertStatus(422)->assertJsonPath('error_code', 'INSUFFICIENT_FUNDS');
        $post($this->transferBody(['amount' => 50]))->assertStatus(422)->assertJsonPath('error_code', 'AMOUNT_OUT_OF_BOUNDS');
        $post($this->transferBody(['amount' => 200000]))->assertStatus(422)->assertJsonPath('error_code', 'AMOUNT_OUT_OF_BOUNDS');
        $post($this->transferBody(['carrier' => 'NOPE']))->assertStatus(422)->assertJsonPath('error_code', 'INVALID_OPERATOR');
        $post($this->transferBody(['country' => 'Narnia']))->assertStatus(422)->assertJsonPath('error_code', 'INVALID_OPERATOR');

        $this->assertSame(0, Transaction::count());
        $this->assertSame(500.0, (float) $merchant->wallet->fresh()->balance);
        Queue::assertNothingPushed();
    }

    public function test_the_balance_can_be_spent_exactly_but_not_beyond(): void
    {
        Queue::fake();
        [$merchant, $plain] = $this->live(2120);
        $post = fn (string $key) => $this->postJson('/api/v1/gateway/transfers', $this->transferBody(['amount' => 1000]), $this->headers($plain, $key));

        $post('a')->assertOk()->assertJsonPath('data.remaining_balance', 1060);
        $post('b')->assertOk()->assertJsonPath('data.remaining_balance', 0);
        $post('c')->assertStatus(422)->assertJsonPath('error_code', 'INSUFFICIENT_FUNDS');
        $this->assertSame(2, Transaction::count());
    }

    // ----------------------------------------------------------------- Retraits / dépôts

    public function test_a_withdrawal_needs_an_active_agency_code(): void
    {
        Queue::fake();
        [$merchant, $plain] = $this->live();
        Agency::factory()->create(['code' => 'AG-OK', 'status' => 'active']);
        Agency::factory()->create(['code' => 'AG-OFF', 'status' => 'inactive']);
        $post = fn (string $code, string $key) => $this->postJson('/api/v1/gateway/withdrawals', $this->transferBody(['agensic_code' => $code]), $this->headers($plain, $key));

        $post('AG-NOPE', 'w1')->assertStatus(422)->assertJsonPath('error_code', 'INVALID_AGENCY_CODE');
        $post('AG-OFF', 'w2')->assertStatus(422)->assertJsonPath('error_code', 'INVALID_AGENCY_CODE');
        $this->assertSame(100000.0, (float) $merchant->wallet->fresh()->balance);

        $post('AG-OK', 'w3')->assertOk()->assertJsonPath('data.type', 'withdrawal')->assertJsonPath('data.total_debited', 1060);
        $this->assertSame(98940.0, (float) $merchant->wallet->fresh()->balance);
        // Côté Digitwave, un retrait marchand est un envoi d'argent (job de transfert).
        Queue::assertPushed(ProcessTransferJob::class, 1);
    }

    public function test_agensic_code_is_required_for_a_withdrawal(): void
    {
        [, $plain] = $this->live();

        $this->postJson('/api/v1/gateway/withdrawals', $this->transferBody(), $this->headers($plain, 'w'))
            ->assertStatus(422)->assertJsonValidationErrors('agensic_code');
    }

    public function test_a_deposit_does_not_debit_the_wallet_and_stays_pending(): void
    {
        Queue::fake();
        [$merchant, $plain] = $this->live();

        $this->postJson('/api/v1/gateway/deposits', $this->transferBody(), $this->headers($plain, 'd1'))
            ->assertOk()->assertJsonPath('data.type', 'deposit')->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.remaining_balance', 100000);

        $this->assertSame(100000.0, (float) $merchant->wallet->fresh()->balance);
        // Un dépôt est une collecte : job de « retrait » Digitwave.
        Queue::assertPushed(ProcessWithdrawalJob::class, 1);
    }

    // ----------------------------------------------------------------- Sandbox

    public function test_a_test_key_creates_simulated_transactions_on_the_sandbox_balance_only(): void
    {
        $merchant = $this->merchant('production', 5000, 20000);
        [, $test] = $this->apiKey($merchant, 'sandbox');

        $this->postJson('/api/v1/gateway/transfers', $this->transferBody(), $this->headers($test, 's1'))
            ->assertOk()->assertJsonPath('data.environment', 'sandbox')->assertJsonPath('data.remaining_balance', 18940);

        $wallet = $merchant->wallet->fresh();
        $this->assertSame(5000.0, (float) $wallet->balance);
        $this->assertSame(18940.0, (float) $wallet->sandbox_balance);
        // La sandbox est traitée de façon synchrone dans les tests (file « sync ») : succès simulé.
        $this->assertSame('success', Transaction::firstOrFail()->status);
    }

    // ----------------------------------------------------------------- Historique

    private function seedHistory(User $merchant): void
    {
        $make = fn (array $o) => DB::table('transactions')->insert(array_merge([
            'reference' => 'TR-'.uniqid(), 'type' => 'transfer', 'channel' => 'merchant_api', 'environment' => 'production',
            'user_id' => $merchant->id, 'recipient_phone' => '677', 'recipient_operator' => 'MTN_CM', 'country_name' => 'Cameroon',
            'amount_sent' => 1000, 'currency_sent' => 'XAF', 'fees' => 60, 'amount_to_receive' => 1000, 'currency_received' => 'XAF',
            'status' => 'success', 'service' => 'MOBILE_MONEY', 'processing_mode' => 'AUTOMATIC', 'created_at' => now(), 'updated_at' => now(),
        ], $o));

        $make(['reference' => 'TR-OLD', 'status' => 'failed', 'created_at' => now()->subDays(10)]);
        $make(['reference' => 'TR-DEP', 'type' => 'deposit', 'status' => 'pending']);
        $make(['reference' => 'TR-OK']);
        $make(['reference' => 'TR-SBX', 'environment' => 'sandbox']);
        $make(['reference' => 'TR-APP', 'channel' => 'mobile_app']);
        $make(['reference' => 'TR-OTHER', 'user_id' => User::factory()->merchant()->create()->id]);
    }

    public function test_history_is_limited_to_the_keys_environment_channel_and_merchant(): void
    {
        [$merchant, $plain] = $this->live();
        $this->seedHistory($merchant);

        $refs = collect($this->getJson('/api/v1/gateway/transactions', $this->headers($plain))->assertOk()->json('data'))->pluck('reference')->sort()->values()->all();

        $this->assertSame(['TR-DEP', 'TR-OK', 'TR-OLD'], $refs);
    }

    public function test_history_filters_and_pagination(): void
    {
        [$merchant, $plain] = $this->live();
        $this->seedHistory($merchant);
        $get = fn (string $qs) => $this->getJson('/api/v1/gateway/transactions'.$qs, $this->headers($plain));

        $get('?type=deposit')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', 'TR-DEP');
        $get('?status=failed')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', 'TR-OLD');
        $get('?date_from='.now()->subDay()->toDateString())->assertOk()->assertJsonCount(2, 'data');
        $get('?date_to='.now()->subDays(5)->toDateString())->assertOk()->assertJsonCount(1, 'data');

        $page = $get('?per_page=2&page=2')->assertOk();
        $page->assertJsonCount(1, 'data')->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 2)->assertJsonPath('meta.total', 3);
    }

    public function test_history_rejects_invalid_filters(): void
    {
        [, $plain] = $this->live();

        foreach (['?status=nope', '?type=nope', '?per_page=101', '?per_page=0', '?date_from=not-a-date'] as $qs) {
            $this->getJson('/api/v1/gateway/transactions'.$qs, $this->headers($plain))->assertStatus(422);
        }
    }

    public function test_a_transaction_is_found_by_reference_only_inside_its_own_scope(): void
    {
        [$merchant, $plain] = $this->live();
        $this->seedHistory($merchant);
        $show = fn (string $ref) => $this->getJson("/api/v1/gateway/transactions/{$ref}", $this->headers($plain));

        $res = $show('TR-OK')->assertOk();
        $this->assertEqualsCanonicalizing(self::TRANSACTION_KEYS, array_keys($res->json('data')));

        foreach (['TR-SBX', 'TR-APP', 'TR-OTHER', 'TR-MISSING'] as $ref) {
            $show($ref)->assertStatus(404)->assertJsonPath('error_code', 'TRANSACTION_NOT_FOUND');
        }
    }

    // ----------------------------------------------------------------- Webhooks

    public function test_a_status_change_triggers_the_merchants_webhook(): void
    {
        Http::fake(['merchant.example/*' => Http::response('ok')]);
        $merchant = $this->merchant('production', 5000, 20000);
        $merchant->webhookEndpoints()->create(['url' => 'https://merchant.example/hook', 'secret' => 'whsec_x', 'environment' => 'sandbox']);
        [, $test] = $this->apiKey($merchant, 'sandbox');

        $ref = $this->postJson('/api/v1/gateway/transfers', $this->transferBody(), $this->headers($test, 'wh1'))->json('data.reference');

        // Sandbox : pending → processing → success, chaque étape notifie le marchand.
        $events = WebhookDelivery::pluck('event')->all();
        $this->assertContains('transaction.success', $events);
        Http::assertSent(fn ($r) => $r['data']['reference'] === $ref && $r['event'] === 'transaction.success');
    }
}
