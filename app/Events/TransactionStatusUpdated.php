<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
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
        // Canal privé (diffusé sous le nom "private-user.{id}") : seul le propriétaire
        // du compte peut s'y abonner, après autorisation par /broadcasting/auth
        // (cf. routes/channels.php).
        return [
            new PrivateChannel('user.'.$this->transaction->user_id),
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
            'status' => $this->transaction->status,
            'amount' => $this->transaction->amount_to_receive,
        ];

        Log::info('[TransactionStatusUpdated] 📦 Payload à diffuser', $payload);

        return $payload;
    }
}
