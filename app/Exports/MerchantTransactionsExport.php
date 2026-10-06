<?php

namespace App\Exports;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Export Excel des transactions d'un marchand : une feuille « Résumé » (marchand, environnement, filtres appliqués,
 * totaux) et une feuille « Transactions » (une ligne par opération).
 */
class MerchantTransactionsExport implements Export, WithMultipleSheets
{
    use Exportable;

    /**
     * @param  array<string, mixed>  $filters  filtres appliqués (pour les rappeler dans le résumé)
     * @param  array<string, int|float>  $summary
     */
    public function __construct(
        private readonly User $merchant,
        private readonly Builder $query,
        private readonly string $environment,
        private readonly array $filters,
        private readonly array $summary,
    ) {}

    public function sheets(): array
    {
        return [
            new MerchantTransactionsSummarySheet($this->merchant, $this->environment, $this->filters, $this->summary),
            new MerchantTransactionsListSheet($this->query),
        ];
    }
}
