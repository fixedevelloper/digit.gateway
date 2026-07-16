<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;
use Throwable;
class DigitwaveService
{
    protected string $baseUrl;
    protected string $apiKey;

    public function __construct()
    {
// On récupère les configurations depuis config/services.php
        $this->baseUrl = config('services.digitwave.url', 'https://digitwave-services.com/api/');
        $this->apiKey = config('services.digitwave.api_key');
    }

    /**
     * 1. Envoyer de l'argent (Transfert / Payout)
     */


public function sendMoney(string $reference, string $country, string $carrier, string $number, float $amount): array
{
    // 1. Log contextuel du démarrage de l'opération
    Log::info("[GATEWAY - Envoi Mobile Money] Début du transfert.", [
         'apikey' => $this->apiKey,
        'country'   => $country,
        'carrier'   => $carrier,
        'number'    => $number, // Idéalement masqué partiellement en prod si réglementation stricte
        'amount'    => $amount
    ]);

    try {
        // 2. Appel de la requête HTTP
        $response = $this->post('send', [
            'apikey' => $this->apiKey,
             'country' => $country,
            'carrier' => $carrier,
            'number'  => $number,
            'amount'  => $amount,
        ]);

        // 3. Log de la réponse reçue de l'agrégateur
        Log::info("[GATEWAY - Envoi Mobile Money] Réponse reçue de l'opérateur.", [
            'reference' => $reference,
            'response'  => $response
        ]);

        return $response;

    } catch (Throwable $e) {
        // 4. Log critique en cas de crash réseau, timeout ou erreur 500 de la passerelle
        Log::error("[GATEWAY - Envoi Mobile Money] Échec critique du canal HTTP.", [
            'reference' => $reference,
            'message'   => $e->getMessage(),
            'code'      => $e->getCode(),
            'trace'     => substr($e->getTraceAsString(), 0, 500) // On limite la taille du log
        ]);

        // On propage l'exception ou on retourne un format d'erreur attendu par ton service
        throw $e;
    }
}

    /**
     * 2. Demander un retrait (Collecte / Cash-In)
     */
    public function requestWithdrawal(string $reference, string $country, string $carrier, string $number, float $amount): array
    {
        return $this->post('withdrawal', [
            'country' => $country,
            'carrier' => $carrier,
            'number' => $number,
            'amount' => $amount,
        ]);
    }

    /**
     * 3. Vérifier le statut d'une requête (Query Mapping)
     * @param string $requestId
     * @return array
     */
    public function checkStatus(string $requestId): array
    {
        try {
            $response = Http::get($this->baseUrl . 'get_request', [
                'apikey' => $this->apiKey,
                'request_id' => $requestId,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            throw new Exception("Échec de la communication avec l'API de statut. Code: " . $response->status());
        } catch (Exception $e) {
            Log::error("DigitwaveService [Status Error]: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Méthode d'aide privée pour factoriser les requêtes POST
     */
    private function post(string $endpoint, array $data): array
    {
        try {
// Fusionner l'API Key automatiquement dans le body de la requête
            $payload = array_merge(['apikey' => $this->apiKey], $data);

            $response = Http::post($this->baseUrl . $endpoint, $payload);

            if ($response->successful()) {
                return $response->json();
            }

            return [
                'success' => false,
                'message' => $response->json()['message'] ?? "Erreur HTTP passerelle ({$response->status()})"
            ];
        } catch (Exception $e) {
            Log::error("DigitwaveService [POST {$endpoint} Error]: " . $e->getMessage());
            return ['success' => false, 'message' => 'Impossible de joindre le fournisseur de paiement.'];
        }
    }
}
