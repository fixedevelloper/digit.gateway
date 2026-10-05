<?php

namespace App\Console\Commands;

use App\Services\Monitoring\AlertService;
use App\Services\Monitoring\CheckResult;
use App\Services\Monitoring\MonitoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class RunMonitoring extends Command
{
    protected $signature = 'monitor:check {--quiet-ok : N\'affiche rien si tout va bien}';

    protected $description = 'Contrôles de santé (file d\'attente, transactions, échecs, disque), alertes et heartbeat';

    public function handle(MonitoringService $monitoring, AlertService $alerts): int
    {
        $results = $monitoring->run();
        $overall = $monitoring->overall($results);

        $alerts->handle($results);
        Cache::forever('monitor:last_run', now()->toIso8601String());

        if (! ($overall === CheckResult::OK && $this->option('quiet-ok'))) {
            foreach ($results as $r) {
                $this->line(sprintf('[%-8s] %s — %s', strtoupper($r->status), $r->label, $r->message));
            }
        }

        // Signal de vie : une absence de ping est détectée par l'outil externe (scheduler arrêté, serveur HS).
        if ($url = config('monitoring.heartbeat_url')) {
            try {
                Http::timeout(10)->get($url);
            } catch (Throwable $e) {
                logger()->warning('[MONITORING] Heartbeat impossible : '.$e->getMessage());
            }
        }

        return $overall === CheckResult::CRITICAL ? self::FAILURE : self::SUCCESS;
    }
}
