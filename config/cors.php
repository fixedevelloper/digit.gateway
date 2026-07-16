<?php

// config/cors.php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost:3000', // Ton client Next.js local
        'http://127.0.0.1:3000',
        env('FRONTEND_URL'),     // Utile pour la production
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true, // Indispensable si tu utilises Sanctum (cookies/sessions)
];
