<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookUrlGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Envoie une livraison de webhook au marchand : une tentative par exécution, avec
 * relances espacées (BACKOFF) jusqu'à épuisement, puis statut `failed` (rejouable
 * depuis le portail). Signature : en-tête X-Digit-Signature = "t=<ts>,v1=<hmac>" où
 * hmac = HMAC-SHA256("<ts>.<corps>", secret) — le timestamp permet de rejeter les rejeux.
 */
class SendWebhookDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    /** Délai (s) avant chaque relance : 30 s, 2 min, 10 min, 1 h, 6 h. */
    public const BACKOFF = [30, 120, 600, 3600, 21600];

    private const TIMEOUT = 10;

    public function __construct(public WebhookDelivery $delivery) {}

    public function handle(WebhookUrlGuard $guard): void
    {
        $delivery = $this->delivery->fresh(['endpoint']);

        if (! $delivery || $delivery->status !== WebhookDelivery::PENDING) {
            return;
        }

        $endpoint = $delivery->endpoint;
        $delivery->increment('attempts');

        if (! $endpoint->active) {
            $this->finish($delivery, WebhookDelivery::FAILED, null, null, 'URL désactivée.');

            return;
        }

        $check = $guard->inspect($endpoint->url);
        if (! $check['ok']) {
            $this->finish($delivery, WebhookDelivery::FAILED, null, null, $check['error']);

            return;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $endpoint->secret);

        $options = ['allow_redirects' => false];
        if ($check['ips'] !== []) {
            // Épingle l'IP vérifiée : empêche un changement DNS entre le contrôle et l'envoi.
            $options['curl'] = [CURLOPT_RESOLVE => ["{$check['host']}:{$check['port']}:{$check['ips'][0]}"]];
        }

        try {
            $response = Http::withOptions($options)
                ->timeout(self::TIMEOUT)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'DigitGateway-Webhook/1.0',
                    'X-Digit-Event' => $delivery->event,
                    'X-Digit-Delivery' => $delivery->uuid,
                    'X-Digit-Signature' => "t={$timestamp},v1={$signature}",
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
        } catch (Throwable $e) {
            $this->retryOrFail($delivery, null, null, $e->getMessage());

            return;
        }

        if ($response->successful()) {
            $this->finish($delivery, WebhookDelivery::DELIVERED, $response->status(), $response->body(), null);

            return;
        }

        $this->retryOrFail($delivery, $response->status(), $response->body(), "Réponse HTTP {$response->status()}");
    }

    private function retryOrFail(WebhookDelivery $delivery, ?int $code, ?string $body, string $error): void
    {
        $delay = self::BACKOFF[$delivery->attempts - 1] ?? null;

        if ($delay === null) {
            $this->finish($delivery, WebhookDelivery::FAILED, $code, $body, $error);

            return;
        }

        $delivery->update([
            'response_code' => $code,
            'response_body' => $body !== null ? mb_substr($body, 0, 1000) : null,
            'last_error' => $error,
            'next_retry_at' => now()->addSeconds($delay),
        ]);

        static::dispatch($delivery)->delay($delay);
    }

    private function finish(WebhookDelivery $delivery, string $status, ?int $code, ?string $body, ?string $error): void
    {
        $delivery->update([
            'status' => $status,
            'response_code' => $code,
            'response_body' => $body !== null ? mb_substr($body, 0, 1000) : null,
            'last_error' => $error,
            'next_retry_at' => null,
            'delivered_at' => $status === WebhookDelivery::DELIVERED ? now() : null,
        ]);
    }
}
