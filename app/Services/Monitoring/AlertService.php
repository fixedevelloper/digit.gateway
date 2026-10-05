<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envoie une alerte par webhook et e-mail, et la journalise. Un état persistant n'est
 * signalé qu'une fois (puis rappelé toutes les `repeat_after_minutes`), et sa résolution est annoncée.
 */
class AlertService
{
    /**
     * @param  array<int, CheckResult>  $results
     */
    public function handle(array $results): void
    {
        foreach ($results as $result) {
            $key = 'monitor:state:'.$result->name;
            $previous = Cache::get($key); // ['status' => ..., 'notified_at' => timestamp]

            if (! $result->isOk()) {
                $isNew = ! $previous || $previous['status'] === CheckResult::OK;
                $repeat = $previous && $previous['status'] !== CheckResult::OK
                    && (now()->timestamp - $previous['notified_at']) >= config('monitoring.repeat_after_minutes') * 60;
                $escalated = $previous && $previous['status'] === CheckResult::WARNING && $result->status === CheckResult::CRITICAL;

                if ($isNew || $repeat || $escalated) {
                    $icon = $result->status === CheckResult::CRITICAL ? '🔴' : '🟠';
                    $this->send("{$icon} {$result->label} : {$result->message}", $result->status === CheckResult::CRITICAL);
                    Cache::forever($key, ['status' => $result->status, 'notified_at' => now()->timestamp]);
                } elseif ($previous) {
                    Cache::forever($key, ['status' => $result->status, 'notified_at' => $previous['notified_at']]);
                }

                continue;
            }

            if ($previous && $previous['status'] !== CheckResult::OK) {
                $this->send("✅ Résolu — {$result->label} : {$result->message}", false);
            }

            Cache::forever($key, ['status' => CheckResult::OK, 'notified_at' => now()->timestamp]);
        }
    }

    /** Alerte ponctuelle (ex. job en échec), dédoublonnée par clé pendant `repeat_after_minutes`. */
    public function once(string $key, string $message, bool $critical = true): void
    {
        if (! Cache::add('monitor:once:'.$key, 1, now()->addMinutes(config('monitoring.repeat_after_minutes')))) {
            return;
        }

        $this->send(($critical ? '🔴 ' : '🟠 ').$message, $critical);
    }

    private function send(string $message, bool $critical): void
    {
        $line = 'Digit Gateway — '.$message;
        $critical ? Log::critical("[MONITORING] {$line}") : Log::warning("[MONITORING] {$line}");

        if ($url = config('monitoring.alert_webhook_url')) {
            try {
                Http::timeout(10)->post($url, ['text' => $line, 'content' => $line]);
            } catch (Throwable $e) {
                Log::error('[MONITORING] Envoi du webhook d\'alerte impossible : '.$e->getMessage());
            }
        }

        if ($to = config('monitoring.alert_email')) {
            try {
                Mail::raw($line, fn ($m) => $m->to($to)->subject('[Digit Gateway] '.mb_substr($message, 0, 80)));
            } catch (Throwable $e) {
                Log::error('[MONITORING] Envoi de l\'e-mail d\'alerte impossible : '.$e->getMessage());
            }
        }
    }
}
