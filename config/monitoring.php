<?php

return [
    /*
    | Alertes (changement d'état d'un contrôle) : webhook Slack/Discord/Mattermost/ntfy (JSON avec
    | les clés `text` et `content`), e-mail, et toujours le log (Log::critical).
    */
    'alert_webhook_url' => env('MONITOR_ALERT_WEBHOOK_URL'),
    'alert_email' => env('MONITOR_ALERT_EMAIL'),

    /*
    | URL "ping" (healthchecks.io, Uptime Kuma push…) appelée à chaque passage du contrôle (1 min).
    | Si les pings cessent, le scheduler ou tout le serveur est en panne : c'est l'outil externe qui alerte.
    */
    'heartbeat_url' => env('MONITOR_HEARTBEAT_URL'),

    // Délai avant de répéter une alerte tant que le problème persiste.
    'repeat_after_minutes' => (int) env('MONITOR_REPEAT_MINUTES', 60),

    'thresholds' => [
        // Job en attente depuis plus de N minutes sans être pris : le worker ne consomme plus.
        'queue_lag_minutes' => (int) env('MONITOR_QUEUE_LAG_MINUTES', 5),
        // Job réservé depuis plus de N minutes : worker bloqué.
        'queue_stuck_minutes' => (int) env('MONITOR_QUEUE_STUCK_MINUTES', 15),
        // Transaction débitée mais jamais envoyée à Digitwave après N minutes.
        'unsubmitted_minutes' => (int) env('MONITOR_UNSUBMITTED_MINUTES', 10),
        // Taux d'échec : fenêtre, volume minimum et seuil (0.5 = 50 %).
        'failure_window_minutes' => (int) env('MONITOR_FAILURE_WINDOW_MINUTES', 30),
        'failure_min_volume' => (int) env('MONITOR_FAILURE_MIN_VOLUME', 5),
        'failure_rate' => (float) env('MONITOR_FAILURE_RATE', 0.5),
        // Transfert manuel non pris en charge depuis N heures.
        'manual_queue_hours' => (int) env('MONITOR_MANUAL_QUEUE_HOURS', 4),
        // Espace disque libre minimal (Mo) sur le volume de l'application.
        'min_free_disk_mb' => (int) env('MONITOR_MIN_FREE_DISK_MB', 2048),
    ],
];
