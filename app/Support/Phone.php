<?php

namespace App\Support;

final class Phone
{
    /**
     * Masque partiellement un numéro de téléphone pour les logs (garde les 2 premiers
     * et 2 derniers chiffres, ex: "06******78"). Les logs applicatifs ne doivent pas
     * exposer de données personnelles en clair.
     */
    public static function mask(?string $number): string
    {
        $number = trim((string) $number);
        $length = strlen($number);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return substr($number, 0, 2).str_repeat('*', $length - 4).substr($number, -2);
    }
}
