<?php

return [
    /*
    | Disque privé des pièces des marchands (KYB). Jamais 'public'. KYB_DISK=s3 pour S3/MinIO.
    */
    'disk' => env('KYB_DISK', 'local'),

    /*
    | Pièces demandées pour qu'un marchand puisse passer en production. `required` : indispensable à la
    | soumission du dossier. `expires` : la pièce porte une date de validité à renseigner (< 3 mois, etc.).
    */
    'documents' => [
        'registration_certificate' => ['label' => 'Registre de commerce / immatriculation', 'required' => true],
        'tax_certificate' => ['label' => 'Attestation d\'identifiant fiscal (NIF / NIU)', 'required' => true],
        'company_statutes' => ['label' => 'Statuts de la société', 'required' => true],
        'legal_rep_id' => ['label' => 'Pièce d\'identité du représentant légal', 'required' => true, 'expires' => true],
        'address_proof' => ['label' => 'Justificatif d\'adresse de l\'entreprise', 'required' => true, 'expires' => true],
        'beneficial_owners' => ['label' => 'Liste des bénéficiaires effectifs (> 25 %)', 'required' => true],
        'bank_statement' => ['label' => 'Attestation bancaire (RIB)', 'required' => false],
        'license' => ['label' => 'Licence réglementaire (activité financière)', 'required' => false],
    ],

    // Informations d'entreprise à renseigner avant de soumettre le dossier.
    'profile_required' => ['registration_number', 'tax_id', 'country', 'address', 'business_description', 'expected_monthly_volume'],

    'max_file_kb' => 5120,

    // Délai laissé aux marchands déjà en production, à la mise en place du KYB, pour compléter leur dossier.
    'grace_days' => (int) env('KYB_GRACE_DAYS', 30),
];
