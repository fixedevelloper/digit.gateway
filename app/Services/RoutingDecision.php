<?php

namespace App\Services;

use App\Enums\ProcessingMode;
use App\Models\CountryService;
use App\Models\Provider;

/**
 * Résultat du routage d'un transfert : mode de traitement, provider retenu
 * (null en manuel) et configuration pays/service appliquée (null en routage historique).
 */
final class RoutingDecision
{
    public function __construct(
        public readonly ProcessingMode $mode,
        public readonly ?Provider $provider = null,
        public readonly ?CountryService $countryService = null,
    ) {}

    public function isManual(): bool
    {
        return $this->mode === ProcessingMode::Manual;
    }
}
