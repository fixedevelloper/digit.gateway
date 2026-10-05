<?php

return [
    /*
    | Disque privé des pièces justificatives KYC (jamais 'public'). KYC_DISK=s3 pour S3/MinIO.
    */
    'disk' => env('KYC_DISK', 'local'),

    /*
    | Niveau 1 = compte créé (téléphone) ; 2 = pièce d'identité vérifiée ; 3 = justificatif
    | de domicile vérifié. Chaque demande vise le niveau immédiatement supérieur.
    */
    'levels' => [1, 2, 3],

    'document_types' => [
        2 => ['national_id', 'passport', 'driver_license'],
        3 => ['proof_of_address'],
    ],
];
