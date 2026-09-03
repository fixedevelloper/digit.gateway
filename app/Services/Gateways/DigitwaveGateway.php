<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGatewayContract;
use App\Support\Phone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class DigitwaveGateway implements PaymentGatewayContract
{
    protected string $baseUrl;

    protected string $apiKey;

    /**
     * Délai maximum (s) avant d'abandonner un appel vers la passerelle. Sans cela, une
     * requête qui ne répond jamais bloque indéfiniment le worker de queue qui la traite.
     */
    private const HTTP_TIMEOUT = 15;

    /**
     * Nombre de tentatives et délai (ms) entre chaque tentative en cas d'erreur réseau
     * (timeout, connexion refusée, DNS...). Ne couvre pas les 4xx/5xx métier de la
     * passerelle, gérés explicitement via $response->successful() ci-dessous. Distinct
     * des tentatives du Job (3 essais / 60s), qui elles couvrent les pannes plus longues
     * et rejouent toute la logique métier.
     */
    private const HTTP_RETRY_TIMES = 2;

    private const HTTP_RETRY_SLEEP_MS = 300;

    public function __construct()
    {
        $this->baseUrl = config('services.digitwave.url', 'https://digitwave-services.com/api/');
        $this->apiKey = config('services.digitwave.api_key');
    }

    public function sendMoney(string $reference, string $country, string $carrier, string $number, float $amount): GatewayResponse
    {
        Log::info('[GATEWAY - Envoi Mobile Money] Début du transfert.', [
            'reference' => $reference,
            'country' => $country,
            'carrier' => $carrier,
            'number' => Phone::mask($number),
            'amount' => $amount,
        ]);

        try {
            $result = $this->post('send', [
                'country' => $country,
                'carrier' => $carrier,
                'number' => $number,
                'amount' => $amount,
            ]);

            Log::info("[GATEWAY - Envoi Mobile Money] Réponse reçue de l'opérateur.", [
                'reference' => $reference,
                'response' => $result->raw,
            ]);

            return $result;
        } catch (Throwable $e) {
            Log::error('[GATEWAY - Envoi Mobile Money] Échec critique du canal HTTP.', [
                'reference' => $reference,
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);

            throw $e;
        }
    }

    public function requestWithdrawal(string $reference, string $country, string $carrier, string $number, float $amount): GatewayResponse
    {
        return $this->post('withdrawal', [
            'country' => $country,
            'carrier' => $carrier,
            'number' => $number,
            'amount' => $amount,
        ]);
    }

    public function checkStatus(string $requestId): GatewayResponse
    {
        return $this->get('get_request', ['request_id' => $requestId]);
    }

    /**
     * Méthode d'aide privée pour factoriser les requêtes POST vers la passerelle.
     * Applique un timeout et des tentatives automatiques sur erreurs réseau.
     */
    private function post(string $endpoint, array $data): GatewayResponse
    {
        try {
            $payload = array_merge(['apikey' => $this->apiKey], $data);

            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->retry(self::HTTP_RETRY_TIMES, self::HTTP_RETRY_SLEEP_MS, throw: false)
                ->post($this->baseUrl.$endpoint, $payload);

            if ($response->successful()) {
                return GatewayResponse::fromArray($response->json() ?? []);
            }

            return GatewayResponse::failure(
                $response->json()['message'] ?? "Erreur HTTP passerelle ({$response->status()})"
            );
        } catch (Throwable $e) {
            Log::error("DigitwaveGateway [POST {$endpoint} Error]: ".$e->getMessage());

            return GatewayResponse::failure('Impossible de joindre le fournisseur de paiement.');
        }
    }

    /**
     * Méthode d'aide privée pour factoriser les requêtes GET vers la passerelle.
     * Applique le même timeout/retry que post() pour un comportement homogène.
     */
    private function get(string $endpoint, array $query): GatewayResponse
    {
        try {
            $payload = array_merge(['apikey' => $this->apiKey], $query);

            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->retry(self::HTTP_RETRY_TIMES, self::HTTP_RETRY_SLEEP_MS, throw: false)
                ->get($this->baseUrl.$endpoint, $payload);

            if ($response->successful()) {
                return GatewayResponse::fromArray($response->json() ?? []);
            }

            return GatewayResponse::failure(
                $response->json()['message'] ?? "Erreur HTTP passerelle ({$response->status()})"
            );
        } catch (Throwable $e) {
            Log::error("DigitwaveGateway [GET {$endpoint} Error]: ".$e->getMessage());

            return GatewayResponse::failure($e->getMessage());
        }
    }
}
