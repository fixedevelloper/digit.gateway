<?php

namespace App\Exports;

use App\Exports\Concerns\WritesTextSafely;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MerchantTransactionsListSheet implements FromQuery, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithMapping, WithStyles, WithTitle
{
    use WritesTextSafely;

    public function __construct(private readonly Builder $query) {}

    public function title(): string
    {
        return 'Transactions';
    }

    /** Lu par blocs : un marchand à fort volume ne charge pas toutes les lignes en mémoire. */
    public function query(): Builder
    {
        return (clone $this->query)->orderBy('id');
    }

    public function headings(): array
    {
        return ['Référence', 'Référence passerelle', 'Date', 'Type', 'Service', 'Statut', 'Bénéficiaire', 'Opérateur / Banque', 'Pays',
            'Montant', 'Devise', 'Frais', 'Montant reçu', 'Devise reçue', 'Taux', 'Motif d\'échec', 'Canal'];
    }

    /** @param \App\Models\Transaction $t */
    public function map($t): array
    {
        $bank = $t->service->value === 'BANK_TRANSFER';

        return [
            $t->reference,
            $t->gateway_reference,
            $t->created_at?->format('d/m/Y H:i:s'),
            $t->type,
            $t->service->value,
            $t->status,
            $bank ? $t->recipient_name : $t->recipient_phone,
            $bank ? $t->bankBeneficiary?->bank_name : $t->recipient_operator,
            $t->country_name,
            (float) $t->amount_sent,
            $t->currency_sent,
            (float) $t->fees,
            (float) $t->amount_to_receive,
            $t->currency_received,
            $t->exchange_rate !== null ? (float) $t->exchange_rate : null,
            $t->failure_reason,
            $t->channel,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');

        return [1 => ['font' => ['bold' => true]]];
    }
}
