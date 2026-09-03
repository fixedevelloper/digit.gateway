<?php

namespace App\Services\Gateways;

final class GatewayResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $requestId = null,
        public readonly ?string $status = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {}

    /**
     * Construit une réponse typée à partir du tableau brut renvoyé par l'API Digitwave.
     * Centralise la logique de "devinette" de clés (request_id à la racine ou sous
     * data.*, statut sous status ou data.status...) auparavant dupliquée dans chaque
     * Job qui consommait le service directement.
     */
    public static function fromArray(array $data): self
    {
        $status = $data['data']['status'] ?? $data['status'] ?? null;

        return new self(
            success: (bool) ($data['success'] ?? false),
            requestId: $data['request_id'] ?? $data['data']['request_id'] ?? null,
            status: $status !== null ? strtoupper($status) : null,
            message: $data['message'] ?? $data['data']['message'] ?? null,
            raw: $data,
        );
    }

    public static function failure(string $message): self
    {
        return new self(success: false, message: $message);
    }
}
