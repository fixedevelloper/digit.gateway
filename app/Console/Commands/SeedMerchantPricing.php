<?php

namespace App\Console\Commands;

use App\Exceptions\TransactionValidationException;
use App\Models\MerchantPricing;
use App\Models\Operator;
use App\Models\User;
use App\Services\ExchangeRateService;
use Illuminate\Console\Command;

/**
 * Reprise des marchands existants lors de la mise en place des tarifs par marchand : crée, pour chaque
 * opérateur actif, un tarif reprenant les frais actuels de l'opérateur et, si les devises diffèrent, le taux
 * global du moment. Ne modifie jamais un tarif existant. À lancer une fois, avant (ou juste après) la migration.
 */
class SeedMerchantPricing extends Command
{
    protected $signature = 'merchants:seed-pricing
        {--merchant= : Limiter à un marchand (id)}
        {--dry-run : Afficher ce qui serait créé sans rien écrire}';

    protected $description = 'Crée les tarifs des marchands existants à partir des frais des opérateurs et du taux global';

    public function handle(ExchangeRateService $rates): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $operators = Operator::where('status', true)->get();

        $merchants = User::where('role', 'merchant')
            ->when($this->option('merchant'), fn ($q, $id) => $q->whereKey($id))
            ->with('wallet')
            ->get();

        if ($merchants->isEmpty()) {
            $this->warn('Aucun marchand concerné.');

            return self::SUCCESS;
        }

        $created = $existing = 0;
        $skipped = [];

        foreach ($merchants as $merchant) {
            $walletCurrency = $merchant->wallet?->currency;

            foreach ($operators as $operator) {
                if (MerchantPricing::where('merchant_id', $merchant->id)->where('operator_id', $operator->id)->exists()) {
                    $existing++;

                    continue;
                }

                $rate = null;
                if ($walletCurrency && $operator->currency !== $walletCurrency) {
                    try {
                        $rate = $rates->rate($operator->currency, $walletCurrency);
                    } catch (TransactionValidationException) {
                        $skipped[] = "{$merchant->company_name} / {$operator->name} : aucun taux global {$operator->currency}/{$walletCurrency}";

                        continue;
                    }
                }

                if (! $dryRun) {
                    MerchantPricing::create([
                        'merchant_id' => $merchant->id,
                        'operator_id' => $operator->id,
                        'fixed_fee' => $operator->fixed_fee,
                        'percent_fee' => $operator->percent_fee,
                        'rate' => $rate,
                        'active' => true,
                    ]);
                }
                $created++;
            }
        }

        $this->info(($dryRun ? '[simulation] ' : '')."{$created} tarif(s) à créer/créés, {$existing} déjà existant(s) conservé(s), ".count($skipped).' ignoré(s).');
        foreach ($skipped as $line) {
            $this->warn("Ignoré — {$line}");
        }

        return self::SUCCESS;
    }
}
