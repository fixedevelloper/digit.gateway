<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Transactions d'un marchand vues par l'admin (sandbox et production). */
class AdminMerchantTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::factory()->merchant()->create(['environment' => 'production']);
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);
    }

    private function tx(array $o = [], ?User $owner = null): Transaction
    {
        $createdAt = $o['created_at'] ?? null;
        unset($o['created_at']);

        $t = Transaction::create($o + [
            'reference' => 'TX-'.strtoupper(uniqid()), 'type' => 'transfer', 'channel' => 'merchant_api', 'environment' => 'production',
            'user_id' => ($owner ?? $this->merchant)->id, 'recipient_phone' => '677000001', 'recipient_operator' => 'MTN_CM', 'country_name' => 'Cameroon',
            'amount_sent' => 1000, 'currency_sent' => 'XAF', 'fees' => 60, 'amount_to_receive' => 1000, 'currency_received' => 'XAF', 'status' => 'success',
        ]);

        if ($createdAt) {
            DB::table('transactions')->where('id', $t->id)->update(['created_at' => $createdAt]);
        }

        return $t;
    }

    private function list(array $query)
    {
        return $this->getJson("/api/admin/merchants/{$this->merchant->id}/transactions?".http_build_query($query));
    }

    public function test_sandbox_and_production_are_listed_separately_for_the_merchant_only(): void
    {
        $this->tx(['reference' => 'TX-LIVE']);
        $this->tx(['reference' => 'TX-SBX', 'environment' => 'sandbox']);
        $this->tx(['reference' => 'TX-OTHER'], User::factory()->merchant()->create());

        $live = collect($this->list(['environment' => 'production'])->assertOk()->json('data'))->pluck('reference')->all();
        $sbx = collect($this->list(['environment' => 'sandbox'])->assertOk()->json('data'))->pluck('reference')->all();

        $this->assertSame(['TX-LIVE'], $live);
        $this->assertSame(['TX-SBX'], $sbx);
    }

    public function test_the_environment_is_required_and_validated(): void
    {
        $this->list([])->assertStatus(422)->assertJsonValidationErrors('environment');
        $this->list(['environment' => 'staging'])->assertStatus(422);
        $this->list(['environment' => 'production', 'status' => 'nope'])->assertStatus(422);
        $this->list(['environment' => 'production', 'per_page' => 101])->assertStatus(422);
    }

    public function test_rows_carry_the_merchant_contract_plus_admin_fields_and_no_internal_leaks(): void
    {
        $this->tx(['gateway_reference' => 'GW-1']);

        $row = $this->list(['environment' => 'production'])->json('data.0');

        $this->assertSame(['TX', 'success', 'merchant_api', 'GW-1'], [substr($row['reference'], 0, 2), $row['status'], $row['channel'], $row['gateway_reference']]);
        $this->assertSame(['phone' => '677000001', 'operator' => 'MTN_CM', 'country' => 'Cameroon'], $row['recipient']);
        foreach (['user_id', 'assigned_agent_id', 'idempotency_key', 'provider_id'] as $internal) {
            $this->assertArrayNotHasKey($internal, $row);
        }
    }

    public function test_filters_search_and_pagination(): void
    {
        $this->tx(['reference' => 'TX-A', 'type' => 'deposit', 'status' => 'pending']);
        $this->tx(['reference' => 'TX-B', 'status' => 'failed', 'recipient_phone' => '699111222']);
        $this->tx(['reference' => 'TX-C', 'created_at' => now()->subDays(10)]);

        $base = ['environment' => 'production'];

        $this->assertSame(['TX-A'], collect($this->list($base + ['type' => 'deposit'])->json('data'))->pluck('reference')->all());
        $this->assertSame(['TX-B'], collect($this->list($base + ['status' => 'failed'])->json('data'))->pluck('reference')->all());
        $this->assertSame(['TX-B'], collect($this->list($base + ['search' => '699111'])->json('data'))->pluck('reference')->all());
        $this->assertSame(['TX-C'], collect($this->list($base + ['search' => 'TX-C'])->json('data'))->pluck('reference')->all());
        $this->assertCount(2, $this->list($base + ['date_from' => now()->subDay()->toDateString()])->json('data'));
        $this->assertCount(1, $this->list($base + ['date_to' => now()->subDays(5)->toDateString()])->json('data'));

        $page = $this->list($base + ['per_page' => 2, 'page' => 2])->assertOk();
        $page->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);
    }

    public function test_the_summary_follows_the_filters_not_the_page(): void
    {
        $this->tx(['amount_sent' => 1000, 'fees' => 60]);
        $this->tx(['amount_sent' => 2000, 'fees' => 90]);
        $this->tx(['amount_sent' => 500, 'status' => 'failed', 'fees' => 30]);
        $this->tx(['amount_sent' => 700, 'status' => 'reversed']);
        $this->tx(['amount_sent' => 9999, 'environment' => 'sandbox']);

        $s = $this->list(['environment' => 'production', 'per_page' => 1])->json('summary');

        $this->assertEquals([4, 2, 2, 3000, 150], [$s['total'], $s['success_count'], $s['failed_count'], $s['success_volume'], $s['success_fees']]);

        $sandbox = $this->list(['environment' => 'sandbox'])->json('summary');
        $this->assertEquals([1, 1, 9999], [$sandbox['total'], $sandbox['success_count'], $sandbox['success_volume']]);

        $empty = $this->list(['environment' => 'production', 'status' => 'processing'])->json('summary');
        $this->assertEquals([0, 0, 0], [$empty['total'], $empty['success_count'], $empty['success_volume']]);
    }

    public function test_only_merchants_exist_here_and_only_admins_may_look(): void
    {
        $customer = User::factory()->create();
        $this->getJson("/api/admin/merchants/{$customer->id}/transactions?environment=production")->assertNotFound();
        $this->getJson('/api/admin/merchants/999999/transactions?environment=production')->assertNotFound();

        foreach ([User::factory()->create(), User::factory()->agent()->create(), User::factory()->merchant()->create()] as $user) {
            Sanctum::actingAs($user, ['*']);
            $this->getJson("/api/admin/merchants/{$this->merchant->id}/transactions?environment=production")->assertForbidden();
        }
    }

    // ------------------------------------------------------------------ Export

    private function export(array $query)
    {
        return $this->get("/api/admin/merchants/{$this->merchant->id}/transactions/export?".http_build_query($query));
    }

    /** @return array{0: \PhpOffice\PhpSpreadsheet\Spreadsheet, 1: string} */
    private function workbook($response): array
    {
        $response->assertOk();
        $file = $response->baseResponse->getFile();

        return [\PhpOffice\PhpSpreadsheet\IOFactory::load($file->getPathname()), $response->headers->get('content-disposition')];
    }

    public function test_the_export_has_a_summary_sheet_and_one_row_per_transaction(): void
    {
        $this->merchant->update(['company_name' => 'Acme Pay & Co']);
        $this->tx(['reference' => 'TX-1', 'amount_sent' => 1000, 'fees' => 60]);
        $this->tx(['reference' => 'TX-2', 'amount_sent' => 500, 'status' => 'failed', 'failure_reason' => 'Rejeté par l\'opérateur']);
        $this->tx(['reference' => 'TX-SBX', 'environment' => 'sandbox']);

        [$book, $disposition] = $this->workbook($this->export(['environment' => 'production']));

        $this->assertSame(['Résumé', 'Transactions'], $book->getSheetNames());
        $this->assertStringContainsString('transactions_acme-pay-co_production_', $disposition);
        $this->assertStringEndsWith('.xlsx', $disposition);

        $list = $book->getSheetByName('Transactions')->toArray();
        $this->assertSame('Référence', $list[0][0]);
        $this->assertSame(['TX-1', 'TX-2'], array_column(array_slice($list, 1), 0));
        $this->assertEquals(1000, $list[1][9]);
        $this->assertSame('Rejeté par l\'opérateur', $list[2][15]);

        $summary = collect($book->getSheetByName('Résumé')->toArray())->mapWithKeys(fn ($r) => [$r[0] => $r[1] ?? null]);
        $this->assertSame('Acme Pay & Co', $summary['Marchand']);
        $this->assertSame('Production', $summary['Environnement']);
        $this->assertEquals([2, 1, 1, 1000], [$summary['Transactions'], $summary['Réussies'], $summary['Échecs / rejetées / remboursées'], $summary['Volume réussi']]);
    }

    public function test_the_sandbox_export_is_separate_and_labelled_simulated(): void
    {
        $this->tx(['reference' => 'TX-LIVE']);
        $this->tx(['reference' => 'TX-SBX', 'environment' => 'sandbox']);

        [$book] = $this->workbook($this->export(['environment' => 'sandbox']));

        $this->assertSame(['TX-SBX'], array_column(array_slice($book->getSheetByName('Transactions')->toArray(), 1), 0));
        $this->assertStringContainsString('Sandbox', $book->getSheetByName('Résumé')->getCell('B3')->getValue());
    }

    public function test_the_export_applies_the_same_filters_and_recalls_them(): void
    {
        $this->tx(['reference' => 'TX-A', 'status' => 'failed']);
        $this->tx(['reference' => 'TX-B']);

        [$book] = $this->workbook($this->export(['environment' => 'production', 'status' => 'failed']));

        $this->assertSame(['TX-A'], array_column(array_slice($book->getSheetByName('Transactions')->toArray(), 1), 0));
        $this->assertStringContainsString('Statut : failed', $book->getSheetByName('Résumé')->getCell('B4')->getValue());
    }

    public function test_text_typed_by_a_merchant_never_becomes_an_excel_formula(): void
    {
        // Le numéro de bénéficiaire est une chaîne libre envoyée par le marchand.
        $this->tx(['reference' => 'TX-EVIL', 'recipient_phone' => '=HYPERLINK("http://evil.example","clic")', 'failure_reason' => '+cmd|\' /C calc\'!A0']);

        [$book] = $this->workbook($this->export(['environment' => 'production']));
        $sheet = $book->getSheetByName('Transactions');

        foreach (['G2', 'P2'] as $address) {
            $cell = $sheet->getCell($address);
            $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $cell->getDataType(), $address);
        }
        $this->assertSame('=HYPERLINK("http://evil.example","clic")', $sheet->getCell('G2')->getValue());
    }

    public function test_amounts_stay_numeric_so_they_can_be_summed(): void
    {
        $this->tx(['amount_sent' => 1500.5]);

        [$book] = $this->workbook($this->export(['environment' => 'production']));
        $cell = $book->getSheetByName('Transactions')->getCell('J2');

        $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC, $cell->getDataType());
        $this->assertEquals(1500.5, $cell->getValue());
    }

    public function test_an_oversized_export_is_refused_with_advice(): void
    {
        config(['exports.merchant_transactions_max_rows' => 2]);
        foreach (range(1, 3) as $i) {
            $this->tx();
        }

        $this->export(['environment' => 'production'])->assertStatus(422);
        $this->export(['environment' => 'production', 'status' => 'failed'])->assertOk();
    }

    public function test_export_validation_and_access(): void
    {
        $this->export([])->assertStatus(422)->assertJsonValidationErrors('environment');
        $this->getJson("/api/admin/merchants/{$this->merchant->id}/transactions/export?environment=staging")->assertStatus(422);
        $this->getJson('/api/admin/merchants/999999/transactions/export?environment=production')->assertNotFound();
        $this->getJson('/api/admin/merchants/'.User::factory()->create()->id.'/transactions/export?environment=production')->assertNotFound();

        foreach ([User::factory()->create(), User::factory()->agent()->create(), User::factory()->merchant()->create()] as $user) {
            Sanctum::actingAs($user, ['*']);
            $this->getJson("/api/admin/merchants/{$this->merchant->id}/transactions/export?environment=production")->assertForbidden();
        }
    }
}
