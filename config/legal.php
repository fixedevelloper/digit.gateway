<?php

/*
| Versions en vigueur des documents légaux (même format que `lastUpdated` côté app Flutter).
| Les changer force tous les utilisateurs à ré-accepter au prochain lancement de l'app.
| `info` alimente les pages publiques /terms et /privacy : à compléter dans le .env.
*/
return [
    'terms_version' => env('LEGAL_TERMS_VERSION') ?: '2026-10-04',
    'privacy_version' => env('LEGAL_PRIVACY_VERSION') ?: '2026-10-04',

    'info' => [
        'company' => env('LEGAL_COMPANY') ?: '[À COMPLÉTER : NOM DE LA SOCIÉTÉ / COMPANY NAME]',
        'address' => env('LEGAL_ADDRESS') ?: '[À COMPLÉTER : ADRESSE / ADDRESS]',
        'registration' => env('LEGAL_REGISTRATION') ?: '[À COMPLÉTER : RCCM / REGISTRATION No.]',
        'country' => env('LEGAL_COUNTRY') ?: '[À COMPLÉTER : PAYS / COUNTRY]',
        'jurisdiction' => env('LEGAL_JURISDICTION') ?: '[À COMPLÉTER : TRIBUNAL COMPÉTENT / COMPETENT COURT]',
        'support_email' => env('LEGAL_SUPPORT_EMAIL') ?: '[À COMPLÉTER : support@exemple.com]',
        'privacy_email' => env('LEGAL_PRIVACY_EMAIL') ?: '[À COMPLÉTER : privacy@exemple.com]',
        'support_phone' => env('LEGAL_SUPPORT_PHONE') ?: '[À COMPLÉTER : +237 6XX XX XX XX]',
        'retention' => env('LEGAL_RETENTION') ?: '[À COMPLÉTER : 10 ans / 10 years]',
    ],
];
