<?php

return [
    /*
    | Les actions financières et sensibles de la console admin (ajustement de wallet,
    | résolution de rapprochement, plafonds KYC, mot de passe d'un utilisateur, passage
    | d'un marchand en production) exigent que le compte ait activé la 2FA.
    */
    'require_admin_2fa' => env('SECURITY_REQUIRE_ADMIN_2FA', true),

    /*
    | Double validation : un ajustement de wallet supérieur à ce montant (devise du wallet),
    | ou demandé par un simple admin, reste « pending » jusqu'à l'approbation d'un AUTRE
    | superadmin. Un superadmin applique directement les ajustements jusqu'à ce seuil.
    */
    'adjustment_approval_threshold' => (float) env('SECURITY_ADJUSTMENT_APPROVAL_THRESHOLD', 100000),

    // Émetteur affiché dans l'application d'authentification.
    'two_factor_issuer' => env('SECURITY_2FA_ISSUER', 'Digit Gateway'),
];
