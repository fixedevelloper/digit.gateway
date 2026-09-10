<?php

// config/cors.php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter([
        'http://localhost:3000', // Ton client Next.js local
        'http://127.0.0.1:3000',
        env('FRONTEND_URL'),     // Utile pour la production
    ])),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With', 'X-XSRF-TOKEN'],
    // 'Content-Disposition' est indispensable au dashboard pour nommer les fichiers
    // téléchargés (export Excel/PDF des transactions) côté navigateur.
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 0,
    'supports_credentials' => true, // Indispensable si tu utilises Sanctum (cookies/sessions)
];
