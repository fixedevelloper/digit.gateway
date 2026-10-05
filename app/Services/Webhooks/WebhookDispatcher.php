<?php

namespace App\Services\Webhooks;

use App\Jobs\SendWebhookDelivery;
use App\Models\Transaction;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Str;

/**
 * Crée les livraisons de webhook marchand (une par URL active de l'environnement de la
 * transaction) et les confie à la file. Le corps est figé à la création : un rejeu envoie
 * exactement le même événement.
 */
class WebhookDispatcher
{
    public function transactionChanged(Transaction $transaction): void
    {
        $endpoints = WebhookEndpoint::where('user_id', $transaction->user_id)
            ->where('environment', $transaction->environment)
            ->where('active', true)
            ->get();

        foreach ($endpoints as $endpoint) {
            $this->queue($endpoint, 'transaction.'.$transaction->status, $transaction->toMerchantArray(), $transaction->id);
        }
    }

    /** Événement de test envoyé depuis le portail. */
    public function ping(WebhookEndpoint $endpoint): WebhookDelivery
    {
        return $this->queue($endpoint, 'ping', ['message' => 'Test de webhook Digit Gateway.']);
    }

    private function queue(WebhookEndpoint $endpoint, string $event, array $data, ?int $transactionId = null): WebhookDelivery
    {
        $uuid = (string) Str::uuid();

        $delivery = WebhookDelivery::create([
            'uuid' => $uuid,
            'webhook_endpoint_id' => $endpoint->id,
            'transaction_id' => $transactionId,
            'event' => $event,
            'payload' => [
                'id' => $uuid,
                'event' => $event,
                'created_at' => now()->toIso8601String(),
                'data' => $data,
            ],
        ]);

        SendWebhookDelivery::dispatch($delivery)->afterCommit();

        return $delivery;
    }
}
