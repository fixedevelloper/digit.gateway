<?php

use App\Services\Gateways\DigitwaveGateway;

return [

    /*
    |--------------------------------------------------------------------------
    | Providers automatiques implémentés
    |--------------------------------------------------------------------------
    | Mappe le `providers.code` (table configurée par l'admin) vers la classe qui
    | exécute réellement le transfert, par service. Un provider actif en base mais
    | absent d'ici n'est jamais appelé : le transfert bascule en traitement manuel.
    */
    'gateways' => [
        'MOBILE_MONEY' => [
            'digitwave' => DigitwaveGateway::class,
        ],
        'BANK_TRANSFER' => [],
    ],

    /*
    | Provider utilisé quand aucun `country_services` n'est configuré pour le pays en
    | Mobile Money (comportement historique : tout passe par Digitwave).
    */
    'default_mobile_money_provider' => 'digitwave',

    /*
    | Champs bancaires obligatoires quand l'admin n'a défini aucune règle pour le pays
    | (table bank_field_rules). L'IBAN n'est volontairement jamais obligatoire par défaut.
    */
    'bank_default_required' => ['full_name', 'bank_name', 'account_number'],

    /*
    | Disque (config/filesystems.php) des preuves de transfert : privé par défaut ('local').
    | Mettre TRANSFER_PROOF_DISK=s3 pour S3/MinIO.
    */
    'proof_disk' => env('TRANSFER_PROOF_DISK', 'local'),

    /*
    | Un transfert manuel ne peut être validé (COMPLETED) qu'avec au moins une preuve.
    */
    'proof_required_on_complete' => env('TRANSFER_PROOF_REQUIRED', true),
];
