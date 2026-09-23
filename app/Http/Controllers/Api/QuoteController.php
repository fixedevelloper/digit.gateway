<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TransactionValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\QuoteRequest;
use App\Models\Quote;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;

/**
 * Cotation avant validation d'un transfert, retrait ou dépôt. Partagé par l'app
 * mobile (POST /quote, Sanctum) et les marchands (POST /v1/gateway/quotes, clé API).
 */
class QuoteController extends Controller
{
    public function __construct(private readonly TransactionService $transactions)
    {
    }

    /**
     * Demander une cotation
     *
     * Le client saisit un montant dans la devise de son wallet (ex: 10 000 XAF) ; la
     * réponse donne l'équivalent dans la devise de l'opérateur (ex: 16.52 USD), les
     * frais, le total débité et le taux appliqué. Le taux est garanti jusqu'à
     * `expires_at` : pour valider, renvoyer `quote_id` avec le même `amount` sur
     * l'endpoint de transfert / retrait / dépôt. Obligatoire quand la devise de
     * l'opérateur diffère de celle du wallet.
     */
    public function store(QuoteRequest $request): JsonResponse
    {
        $type = $request->input('type', 'transfer');

        // Côté marchand, la clé API doit avoir le droit d'écriture du type d'opération coté.
        $apiKey = $request->attributes->get('api_key');
        if ($apiKey && ! $apiKey->hasScope("{$type}.write")) {
            return response()->json([
                'status' => 'error',
                'message' => "Cette clé API n'a pas la permission '{$type}.write'.",
            ], 403);
        }

        try {
            $quote = $this->transactions->createQuote(
                $request->user(),
                $request->only(['operator_id', 'country', 'carrier', 'currency', 'amount']),
                $type
            );
        } catch (TransactionValidationException $e) {
            return response()->json([
                'status' => 'error',
                'error_code' => $e->errorCode,
                'message' => collect($e->errors())->flatten()->first(),
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->format($quote),
        ], 201);
    }

    private function format(Quote $quote): array
    {
        return [
            'quote_id' => $quote->id,
            'type' => $quote->type,
            'operator' => [
                'id' => $quote->operator->id,
                'code' => $quote->operator->code,
                'name' => $quote->operator->name,
                'currency' => $quote->operator->currency,
            ],
            'amount' => $quote->amount,
            'fee' => $quote->fee,
            'total' => $quote->total,
            'currency' => $quote->currency,
            'converted_amount' => $quote->converted_amount,
            'converted_fee' => $quote->converted_fee,
            'converted_currency' => $quote->converted_currency,
            'rate' => $quote->rate,
            'expires_at' => $quote->expires_at->toIso8601String(),
        ];
    }
}
