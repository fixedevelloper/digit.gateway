<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TransactionStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $transaction;

    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
    }

    public function broadcastOn(): array
    {
        $channelName = 'user.' . $this->transaction->user_id;

        Log::info('[TransactionStatusUpdated] 📡 Canal public déterminé', [
            'channel' => $channelName,
        ]);

        // Channel (public) au lieu de PrivateChannel : pas d'authentification requise
        return [
            new Channel($channelName),
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

        Log::info('[TransactionStatusUpdated] 📦 Payload à diffuser', $payload);

        return $payload;
    }
}
