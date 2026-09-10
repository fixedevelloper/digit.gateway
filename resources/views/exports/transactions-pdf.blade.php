<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 10px; color: #1e293b; }
        h1 { font-size: 16px; margin-bottom: 2px; }
        .subtitle { font-size: 11px; color: #64748b; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #e2e8f0; padding: 5px 6px; text-align: left; }
        th { background-color: #f1f5f9; font-weight: bold; text-transform: uppercase; font-size: 8px; }
        .amount { text-align: right; font-family: monospace; }
        .status-success { color: #059669; font-weight: bold; }
        .status-failed, .status-reversed { color: #dc2626; font-weight: bold; }
        .status-pending, .status-processing { color: #d97706; font-weight: bold; }
        .footer { margin-top: 12px; font-size: 8px; color: #94a3b8; }
    </style>
</head>
<body>
    <h1>Digit Gateway — Journal d'Audit des Transactions</h1>
    <div class="subtitle">
        @if($dateFrom || $dateTo)
            Période : {{ $dateFrom ?: '…' }} au {{ $dateTo ?: '…' }} &bull;
        @endif
        {{ $transactions->count() }} transactions &bull; Généré le {{ now()->format('d/m/Y à H:i') }}
    </div>

    <table>
        <thead>
        <tr>
            <th>Référence</th>
            <th>Type</th>
            <th>Opérateur</th>
            <th>Bénéficiaire</th>
            <th>Téléphone</th>
            <th>Montant</th>
            <th>Marge Nette</th>
            <th>Statut</th>
            <th>Date</th>
        </tr>
        </thead>
        <tbody>
        @foreach($transactions as $tx)
            <tr>
                <td>{{ $tx->reference }}</td>
                <td>{{ $tx->type }}</td>
                <td>{{ $tx->recipient_operator }}</td>
                <td>{{ $tx->recipient_name ?: 'Utilisateur Externe' }}</td>
                <td>{{ $tx->recipient_phone }}</td>
                <td class="amount">{{ number_format((float) $tx->amount_sent, 0, ',', ' ') }} {{ $tx->currency_sent }}</td>
                <td class="amount">{{ number_format((float) $tx->fees - (float) $tx->gateway_fees, 0, ',', ' ') }} {{ $tx->currency_sent }}</td>
                <td class="status-{{ $tx->status }}">{{ $tx->status }}</td>
                <td>{{ optional($tx->created_at)->format('d/m/Y H:i') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="footer">Document généré automatiquement par Digit-Gateway Admin Console.</div>
</body>
</html>
