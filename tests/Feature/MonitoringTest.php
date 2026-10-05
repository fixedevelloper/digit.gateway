<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Services\Monitoring\CheckResult;
use App\Services\Monitoring\MonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
    }

    private function tx(array $overrides = []): Transaction
    {
        // created_at n'est pas assignable en masse : posé directement en base.
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);

        $tx = Transaction::create(array_merge([
            'reference' => 'TR-'.uniqid(),
            'type' => 'transfer',
            'environment' => 'production',
            'user_id' => User::factory()->create()->id,
            'recipient_phone' => '677000000',
            'recipient_operator' => 'MTN_CM',
            'country_name' => 'Cameroon',
            'amount_sent' => 1000,
            'currency_sent' => 'XAF',
            'fees' => 0,
            'amount_to_receive' => 1000,
            'currency_received' => 'XAF',
            'status' => 'processing',
        ], $overrides));

        if ($createdAt) {
            DB::table('transactions')->where('id', $tx->id)->update(['created_at' => $createdAt]);
        }

        return $tx->fresh();
    }

    private function check(string $name): CheckResult
    {
        return collect(app(MonitoringService::class)->run())->firstWhere('name', $name);
    }

    public function test_healthy_platform_is_all_ok(): void
    {
        $results = app(MonitoringService::class)->run();

        $this->assertSame(CheckResult::OK, app(MonitoringService::class)->overall($results));
    }

    public function test_stalled_queue_is_critical(): void
    {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subMinutes(10)->timestamp, 'created_at' => now()->subMinutes(10)->timestamp]);

        $this->assertSame(CheckResult::CRITICAL, $this->check('queue_backlog')->status);
    }

    public function test_recent_jobs_are_not_a_backlog(): void
    {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);

        $this->assertSame(CheckResult::OK, $this->check('queue_backlog')->status);
    }

    public function test_debited_but_never_submitted_transaction_is_critical_only_when_automatic_and_old(): void
    {
        $this->tx(['submitted_at' => null, 'created_at' => now()->subMinutes(20)]);
        $this->assertSame(CheckResult::CRITICAL, $this->check('unsubmitted')->status);
        $this->assertSame(1, $this->check('unsubmitted')->value);
    }

    public function test_unsubmitted_ignores_recent_manual_sandbox_and_submitted_transactions(): void
    {
        $this->tx(['submitted_at' => null]);                                                         // récente
        $this->tx(['submitted_at' => null, 'created_at' => now()->subHour(), 'processing_mode' => 'MANUAL']);
        $this->tx(['submitted_at' => null, 'created_at' => now()->subHour(), 'environment' => 'sandbox']);
        $this->tx(['submitted_at' => now()->subHour(), 'created_at' => now()->subHour()]);

        $this->assertSame(CheckResult::OK, $this->check('unsubmitted')->status);
    }

    public function test_failure_rate_needs_volume_and_a_high_ratio(): void
    {
        foreach (range(1, 3) as $i) {
            $this->tx(['status' => 'failed']);
        }
        $this->assertSame(CheckResult::OK, $this->check('failure_rate')->status, 'volume insuffisant');

        foreach (range(1, 3) as $i) {
            $this->tx(['status' => 'success']);
        }
        $this->assertSame(CheckResult::CRITICAL, $this->check('failure_rate')->status);   // 3/6 = 50 %

        foreach (range(1, 6) as $i) {
            $this->tx(['status' => 'success']);
        }
        $this->assertSame(CheckResult::OK, $this->check('failure_rate')->status);         // 3/12 = 25 %
    }

    public function test_reconciliation_backlog_is_a_warning(): void
    {
        $this->tx(['submitted_at' => now()->subMinutes(10), 'gateway_reference' => null]);

        $this->assertSame(CheckResult::WARNING, $this->check('reconciliation')->status);
    }

    public function test_one_failing_check_does_not_hide_the_others(): void
    {
        \Illuminate\Support\Facades\Schema::drop('failed_jobs');
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subHour()->timestamp, 'created_at' => now()->subHour()->timestamp]);

        $this->assertSame(CheckResult::CRITICAL, $this->check('queue_backlog')->status);
        $this->assertSame(CheckResult::OK, $this->check('failed_jobs')->status);
    }

    // ----------------------------------------------------------------- Alertes

    public function test_alert_is_sent_once_then_resolution_is_announced(): void
    {
        Http::fake();
        config(['monitoring.alert_webhook_url' => 'https://hooks.example/alert']);
        $this->tx(['submitted_at' => null, 'created_at' => now()->subHour()]);

        $this->artisan('monitor:check')->assertExitCode(1);
        $this->artisan('monitor:check')->assertExitCode(1);   // même problème : pas de 2e alerte
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['text'], 'Transactions non envoyées') && $r['content'] === $r['text']);

        Transaction::query()->update(['submitted_at' => now()]);
        $this->artisan('monitor:check')->assertExitCode(0);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains($r['text'], 'Résolu'));
    }

    public function test_persistent_problem_is_repeated_after_the_delay(): void
    {
        Http::fake();
        config(['monitoring.alert_webhook_url' => 'https://hooks.example/alert', 'monitoring.repeat_after_minutes' => 60]);
        $this->tx(['submitted_at' => null, 'created_at' => now()->subHour()]);

        $this->artisan('monitor:check');
        $this->travel(61)->minutes();
        $this->artisan('monitor:check');

        Http::assertSentCount(2);
    }

    public function test_heartbeat_is_pinged_on_every_run(): void
    {
        Http::fake();
        config(['monitoring.heartbeat_url' => 'https://hc.example/ping']);

        $this->artisan('monitor:check --quiet-ok')->assertExitCode(0);
        $this->artisan('monitor:check --quiet-ok')->assertExitCode(0);

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->url() === 'https://hc.example/ping');
    }

    public function test_email_alert_is_sent_to_the_configured_address(): void
    {
        config(['monitoring.alert_email' => 'ops@example.com']);
        $this->tx(['submitted_at' => null, 'created_at' => now()->subHour()]);

        $this->artisan('monitor:check');

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('ops@example.com', $messages[0]->getEnvelope()->getRecipients()[0]->getAddress());
    }

    public function test_failing_webhook_url_never_breaks_the_check(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        config(['monitoring.alert_webhook_url' => 'https://hooks.example/alert']);
        $this->tx(['submitted_at' => null, 'created_at' => now()->subHour()]);

        $this->artisan('monitor:check')->assertExitCode(1);
        $this->assertNotNull(Cache::get('monitor:last_run'));
    }

    public function test_failed_job_raises_a_deduplicated_alert(): void
    {
        Http::fake();
        config(['monitoring.alert_webhook_url' => 'https://hooks.example/alert']);
        $alerts = app(\App\Services\Monitoring\AlertService::class);

        $alerts->once('job_failed:X', 'Job en échec : X');
        $alerts->once('job_failed:X', 'Job en échec : X');

        Http::assertSentCount(1);
    }

    // --------------------------------------------------------------------- API

    public function test_admin_endpoint_reports_checks_and_scheduler_freshness(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);

        $this->getJson('/api/admin/monitoring')->assertOk()
            ->assertJsonPath('data.overall', 'ok')
            ->assertJsonPath('data.scheduler_stale', true)
            ->assertJsonCount(8, 'data.checks');

        $this->artisan('monitor:check --quiet-ok');
        $this->getJson('/api/admin/monitoring')->assertJsonPath('data.scheduler_stale', false);
    }

    public function test_endpoint_is_not_available_to_customers(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson('/api/admin/monitoring')->assertForbidden();
    }
}
