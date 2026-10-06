<?php

namespace App\Exports;

use App\Exports\Concerns\WritesTextSafely;
use App\Models\User;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MerchantTransactionsSummarySheet implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithStyles, WithTitle
{
    use WritesTextSafely;

    public function __construct(private readonly User $merchant, private readonly string $environment, private readonly array $filters, private readonly array $summary) {}

    public function title(): string
    {
        return 'Résumé';
    }

    public function array(): array
    {
        $labels = ['type' => 'Type', 'status' => 'Statut', 'search' => 'Recherche', 'date_from' => 'Du', 'date_to' => 'Au'];
        $applied = collect($labels)->map(fn ($label, $key) => isset($this->filters[$key]) ? "{$label} : {$this->filters[$key]}" : null)->filter()->implode(' · ');

        return [
            ['Marchand', $this->merchant->company_name ?: $this->merchant->name],
            ['Contact', trim("{$this->merchant->name} · {$this->merchant->email}", ' ·')],
            ['Environnement', $this->environment === 'production' ? 'Production' : 'Sandbox (opérations simulées)'],
            ['Filtres', $applied ?: 'Aucun'],
            ['Exporté le', now()->format('d/m/Y H:i')],
            [],
            ['Transactions', $this->summary['total']],
            ['Réussies', $this->summary['success_count']],
            ['Échecs / rejetées / remboursées', $this->summary['failed_count']],
            ['Volume réussi', $this->summary['success_volume']],
            ['Frais sur les réussies', $this->summary['success_fees']],
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return ['A' => ['font' => ['bold' => true]]];
    }
}
