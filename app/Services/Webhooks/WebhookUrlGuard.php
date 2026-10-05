<?php

namespace App\Services\Webhooks;

/**
 * Protection SSRF : une URL de webhook ne doit jamais pointer vers le réseau interne
 * (localhost, 10.x, 192.168.x, métadonnées cloud 169.254.x...). Vérifié à l'enregistrement
 * ET avant chaque envoi (le DNS peut changer entre-temps).
 */
class WebhookUrlGuard
{
    /**
     * @return array{ok: bool, error: ?string, host: ?string, port: int, ips: array<int, string>}
     */
    public function inspect(string $url): array
    {
        $relaxed = app()->environment(['local', 'testing']);
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? null;

        $fail = fn (string $error) => ['ok' => false, 'error' => $error, 'host' => $host, 'port' => 0, 'ips' => []];

        if (! $host || ! in_array($scheme, $relaxed ? ['http', 'https'] : ['https'], true)) {
            return $fail($relaxed ? 'URL http(s) invalide.' : 'L\'URL doit être en https.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return $fail('L\'URL ne doit pas contenir d\'identifiants.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_values(array_unique(array_merge(
                array_column(@dns_get_record($host, DNS_A) ?: [], 'ip'),
                array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
            )));

        if ($relaxed) {
            return ['ok' => true, 'error' => null, 'host' => $host, 'port' => $port, 'ips' => []];
        }

        if ($ips === []) {
            return $fail('Nom de domaine introuvable.');
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $fail('L\'URL pointe vers une adresse réseau non autorisée.');
            }
        }

        return ['ok' => true, 'error' => null, 'host' => $host, 'port' => $port, 'ips' => $ips];
    }
}
