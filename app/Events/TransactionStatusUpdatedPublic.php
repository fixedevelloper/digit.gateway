<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TransactionStatusUpdatedPublic implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $transaction;

    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
    }

    public function broadcastOn(): array
    {
        // Channel (pas PrivateChannel) = pas d'authentification requise
        return [
            new Channel('transactions-test'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'TransactionStatusUpdated';
    }

    public function broadcastWith(): array
    {
        $payload = [
            'reference' => $this->transaction->reference,
            'status'    => $this->transaction->status,
            'amount'    => $this->transaction->amount_to_receive,
        ];

        Log::info('[TransactionStatusUpdatedPublic] 📦 Payload à diffuser (canal public)', $payload);

        return $payload;
    }
}
