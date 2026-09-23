<?php

return [

    /*
     * Durée de validité (secondes) d'une cotation (POST /quote) : le taux affiché
     * au client est garanti pendant ce délai, au-delà il doit en redemander une.
     */
    'quote_ttl' => (int) env('EXCHANGE_QUOTE_TTL', 300),

    /*
     * Nombre de décimales par devise pour les montants convertis. Toute devise
     * absente de la liste utilise 2 décimales.
     */
    'precision' => [
        'XAF' => 0,
        'XOF' => 0,
        'CDF' => 2,
        'USD' => 2,
        'EUR' => 2,
        'TZS' => 0,
    ],

];
