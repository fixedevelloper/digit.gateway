<?php

namespace App\Services;

use App\Exceptions\TransactionValidationException;
use App\Models\ExchangeRate;

/**
 * Lecture des taux de change manuels (table exchange_rates) et arrondis par
 * devise. Une paire saisie dans un sens (ex: USD→XAF) sert aussi dans l'autre
 * sens (XAF→USD) par inversion : l'admin n'a qu'un seul taux à maintenir.
 */
class ExchangeRateService
{
    /**
     * Nombre d'unités de $to pour 1 unité de $from (ex: rate('USD', 'XAF') = 605).
     *
     * @throws TransactionValidationException si aucun taux n'est configuré pour la paire
     */
    public function rate(string $from, string $to): float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return 1.0;
        }

        $direct = $this->latest($from, $to);
        if ($direct) {
            return $direct->rate;
        }

        $inverse = $this->latest($to, $from);
        if ($inverse) {
            return 1 / $inverse->rate;
        }

        throw TransactionValidationException::make(
            'EXCHANGE_RATE_UNAVAILABLE',
            'currency',
            "Aucun taux de change {$from}/{$to} n'est configuré pour le moment."
        );
    }

    /**
     * Arrondi inférieur à la précision de la devise (ex: 16.528 USD → 16.52).
     * Utilisé pour un montant versé : on ne verse jamais plus que ce qui a été payé.
     */
    public function floor(float $amount, string $currency): float
    {
        $factor = 10 ** $this->precision($currency);

        // round(…, 6) neutralise les erreurs binaires (16.53 stocké 16.529999…).
        return floor(round($amount * $factor, 6)) / $factor;
    }

    /**
     * Arrondi supérieur à la précision de la devise (ex: 60.1 XAF → 61).
     * Utilisé pour un montant encaissé (frais, collecte d'un dépôt).
     */
    public function ceil(float $amount, string $currency): float
    {
        $factor = 10 ** $this->precision($currency);

        return ceil(round($amount * $factor, 6)) / $factor;
    }

    public function precision(string $currency): int
    {
        return (int) (config('exchange.precision')[strtoupper($currency)] ?? 2);
    }

    private function latest(string $base, string $quote): ?ExchangeRate
    {
        return ExchangeRate::where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->latest('id')
            ->first();
    }
}
