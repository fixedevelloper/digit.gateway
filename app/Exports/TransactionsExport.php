<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TransactionsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function __construct(private readonly Collection $transactions)
    {
    }

    public function collection(): Collection
    {
        return $this->transactions;
    }

    public function headings(): array
    {
        return [
            'Référence',
            'Référence API',
            'Type',
            'Opérateur',
            'Expéditeur',
            'Bénéficiaire',
            'Téléphone Bénéficiaire',
            'Montant Envoyé',
            'Devise',
            'Frais',
            'Frais Opérateur',
            'Marge Nette',
            'Montant Reçu',
            'Statut',
            'Date',
        ];
    }

    /**
     * @param \App\Models\Transaction $transaction
     */
    public function map($transaction): array
    {
        return [
            $transaction->reference,
            $transaction->gateway_reference,
            $transaction->type,
            $transaction->recipient_operator,
            $transaction->user?->name,
            $transaction->recipient_name,
            $transaction->recipient_phone,
            (float) $transaction->amount_sent,
            $transaction->currency_sent,
            (float) $transaction->fees,
            (float) $transaction->gateway_fees,
            (float) $transaction->fees - (float) $transaction->gateway_fees,
            (float) $transaction->amount_to_receive,
            $transaction->status,
            $transaction->created_at?->format('d/m/Y H:i'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
