<?php

namespace App\Services\Monitoring;

use App\Models\Transaction;
use App\Models\WebhookDelivery;
use App\Services\ReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Contrôles de santé métier : ce que l'on ne voit pas avec un simple « le site répond ».
 * Chaque contrôle est indépendant : une erreur dans l'un ne masque pas les autres.
 */
class MonitoringService
{
    public function __construct(private readonly ReconciliationService $reconciliation) {}

    /** @return array<int, CheckResult> */
    public function run(): array
    {
        $checks = [
            'queue_backlog' => ['File d\'attente', fn () => $this->queueBacklog()],
            'failed_jobs' => ['Jobs en échec (1 h)', fn () => $this->failedJobs()],
            'unsubmitted' => ['Transactions non envoyées', fn () => $this->unsubmittedTransactions()],
            'reconciliation' => ['À rapprocher (Digitwave)', fn () => $this->reconciliationBacklog()],
            'failure_rate' => ['Taux d\'échec', fn () => $this->failureRate()],
            'manual_queue' => ['File manuelle (agents)', fn () => $this->manualQueue()],
            'webhooks' => ['Webhooks marchands', fn () => $this->webhookFailures()],
            'disk' => ['Espace disque', fn () => $this->disk()],
        ];

        $results = [];

        foreach ($checks as $name => [$label, $callback]) {
            try {
                $results[] = $callback();
            } catch (Throwable $e) {
                $results[] = new CheckResult($name, $label, CheckResult::WARNING, 'Contrôle impossible : '.$e->getMessage());
            }
        }

        return $results;
    }

    public function overall(array $results): string
    {
        $statuses = array_map(fn (CheckResult $r) => $r->status, $results);

        return in_array(CheckResult::CRITICAL, $statuses, true) ? CheckResult::CRITICAL
            : (in_array(CheckResult::WARNING, $statuses, true) ? CheckResult::WARNING : CheckResult::OK);
    }

    private function queueBacklog(): CheckResult
    {
        $label = 'File d\'attente';

        if (config('queue.default') !== 'database') {
            return new CheckResult('queue_backlog', $label, CheckResult::OK, 'Non applicable (file non basée sur la base de données).');
        }

        $lag = config('monitoring.thresholds.queue_lag_minutes');
        $stuck = config('monitoring.thresholds.queue_stuck_minutes');

        $waiting = DB::table('jobs')->whereNull('reserved_at')
            ->where('available_at', '<=', now()->subMinutes($lag)->timestamp)->count();
        $blocked = DB::table('jobs')->whereNotNull('reserved_at')
            ->where('reserved_at', '<=', now()->subMinutes($stuck)->timestamp)->count();

        if ($waiting > 0) {
            return new CheckResult('queue_backlog', $label, CheckResult::CRITICAL, "{$waiting} job(s) attendent depuis plus de {$lag} min : le worker `queue` ne consomme plus.", $waiting);
        }

        if ($blocked > 0) {
            return new CheckResult('queue_backlog', $label, CheckResult::CRITICAL, "{$blocked} job(s) bloqué(s) depuis plus de {$stuck} min.", $blocked);
        }

        return new CheckResult('queue_backlog', $label, CheckResult::OK, 'Aucun retard.', 0);
    }

    private function failedJobs(): CheckResult
    {
        $label = 'Jobs en échec (1 h)';

        if (! Schema::hasTable('failed_jobs')) {
            return new CheckResult('failed_jobs', $label, CheckResult::OK, 'Non applicable.');
        }

        $count = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->count();

        return match (true) {
            $count >= 5 => new CheckResult('failed_jobs', $label, CheckResult::CRITICAL, "{$count} jobs en échec sur la dernière heure (`php artisan queue:failed`).", $count),
            $count > 0 => new CheckResult('failed_jobs', $label, CheckResult::WARNING, "{$count} job(s) en échec sur la dernière heure.", $count),
            default => new CheckResult('failed_jobs', $label, CheckResult::OK, 'Aucun échec.', 0),
        };
    }

    /**
     * Argent débité, rien envoyé : le worker a perdu le job, ou la soumission n'a jamais eu lieu.
     */
    private function unsubmittedTransactions(): CheckResult
    {
        $minutes = config('monitoring.thresholds.unsubmitted_minutes');

        $count = Transaction::where('environment', 'production')
            ->where('processing_mode', 'AUTOMATIC')
            ->whereIn('status', ['pending', 'processing'])
            ->whereNull('submitted_at')
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->count();

        return $count > 0
            ? new CheckResult('unsubmitted', 'Transactions non envoyées', CheckResult::CRITICAL, "{$count} transaction(s) automatique(s) jamais envoyée(s) à Digitwave après {$minutes} min.", $count)
            : new CheckResult('unsubmitted', 'Transactions non envoyées', CheckResult::OK, 'Toutes les transactions sont soumises.', 0);
    }

    private function reconciliationBacklog(): CheckResult
    {
        $count = $this->reconciliation->query()->count();

        return $count > 0
            ? new CheckResult('reconciliation', 'À rapprocher (Digitwave)', CheckResult::WARNING, "{$count} transaction(s) à trancher dans « Rapprochement ».", $count)
            : new CheckResult('reconciliation', 'À rapprocher (Digitwave)', CheckResult::OK, 'Rien à rapprocher.', 0);
    }

    private function failureRate(): CheckResult
    {
        $t = config('monitoring.thresholds');
        $since = now()->subMinutes($t['failure_window_minutes']);

        $base = Transaction::where('environment', 'production')->where('processing_mode', 'AUTOMATIC')
            ->whereIn('type', ['transfer', 'withdrawal'])->where('created_at', '>=', $since);

        $total = (clone $base)->count();
        $failed = (clone $base)->where('status', 'failed')->count();

        if ($total < $t['failure_min_volume']) {
            return new CheckResult('failure_rate', 'Taux d\'échec', CheckResult::OK, "Volume trop faible pour conclure ({$total} sur {$t['failure_window_minutes']} min).", $total ? round($failed / $total, 2) : 0);
        }

        $rate = $failed / $total;
        $pct = round($rate * 100);

        return $rate >= $t['failure_rate']
            ? new CheckResult('failure_rate', 'Taux d\'échec', CheckResult::CRITICAL, "{$failed}/{$total} transactions en échec ({$pct} %) sur {$t['failure_window_minutes']} min : Digitwave ou un opérateur est peut-être en panne.", round($rate, 2))
            : new CheckResult('failure_rate', 'Taux d\'échec', CheckResult::OK, "{$failed}/{$total} en échec ({$pct} %).", round($rate, 2));
    }

    private function manualQueue(): CheckResult
    {
        $hours = config('monitoring.thresholds.manual_queue_hours');

        $count = Transaction::where('processing_mode', 'MANUAL')
            ->where('status', Transaction::STATUS_PENDING_MANUAL_REVIEW)
            ->where('created_at', '<=', now()->subHours($hours))
            ->count();

        return $count > 0
            ? new CheckResult('manual_queue', 'File manuelle (agents)', CheckResult::WARNING, "{$count} transfert(s) manuel(s) non pris en charge depuis plus de {$hours} h.", $count)
            : new CheckResult('manual_queue', 'File manuelle (agents)', CheckResult::OK, 'File manuelle à jour.', 0);
    }

    private function webhookFailures(): CheckResult
    {
        $count = WebhookDelivery::where('status', WebhookDelivery::FAILED)->where('updated_at', '>=', now()->subHour())->count();

        return $count >= 10
            ? new CheckResult('webhooks', 'Webhooks marchands', CheckResult::WARNING, "{$count} livraison(s) de webhook abandonnée(s) sur la dernière heure.", $count)
            : new CheckResult('webhooks', 'Webhooks marchands', CheckResult::OK, 'Livraisons normales.', $count);
    }

    private function disk(): CheckResult
    {
        $free = @disk_free_space(storage_path());

        if ($free === false) {
            return new CheckResult('disk', 'Espace disque', CheckResult::OK, 'Non mesurable.');
        }

        $mb = (int) floor($free / 1048576);
        $min = config('monitoring.thresholds.min_free_disk_mb');

        return $mb < $min
            ? new CheckResult('disk', 'Espace disque', CheckResult::CRITICAL, "Il reste {$mb} Mo libres (minimum {$min} Mo) : base, logs et sauvegardes risquent de s'arrêter.", $mb)
            : new CheckResult('disk', 'Espace disque', CheckResult::OK, "{$mb} Mo libres.", $mb);
    }
}
