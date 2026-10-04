<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TransactionValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BankTransferRequest;
use App\Models\Country;
use App\Services\Banking\BankCountryCatalog;
use App\Services\Banking\BankFieldRequirements;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BankTransferController extends Controller
{
    public function __construct(private readonly TransactionService $transactions)
    {
    }

    /**
     * Pays où le virement bancaire est disponible
     *
     * Pays actifs dont l'administration a activé BANK_TRANSFER (automatique ou manuel), avec
     * leur devise et les champs du bénéficiaire à saisir.
     */
    public function countries(BankCountryCatalog $catalog): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'countries' => $catalog->available()->map(function (array $c) {
                $flag = $c['flag'];
                unset($c['flag']);

                return $c + ['flag_url' => $flag ? (str_starts_with($flag, 'http') ? $flag : asset('storage/'.$flag)) : null];
            })->values(),
        ]);
    }

    /**
     * Champs bancaires à saisir pour un pays
     *
     * Liste les champs du bénéficiaire obligatoires pour le pays (configurés par l'administration).
     */
    public function requirements(Request $request, BankFieldRequirements $requirements): JsonResponse
    {
        $country = Country::resolveActive((string) $request->query('country'));

        if (! $country) {
            return response()->json(['status' => 'error', 'message' => 'Pays non supporté ou indisponible.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'country' => $country->name,
            'required_fields' => $requirements->requiredFor($country),
        ]);
    }

    /**
     * Estimer un virement bancaire
     *
     * Renvoie les frais, le total débité et le montant reçu par le bénéficiaire pour un montant
     * donné, avec les mêmes règles que la création (limites du pays, frais, taux, solde). Rien n'est
     * débité ni enregistré ; le taux n'est pas verrouillé et peut légèrement évoluer avant la création.
     */
    public function estimate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country' => 'required|string',
            'amount' => 'required|numeric|min:1',
        ]);

        try {
            $estimate = $this->transactions->estimateBankTransfer($request->user(), $data['country'], (float) $data['amount']);
        } catch (TransactionValidationException $e) {
            return response()->json([
                'status' => 'error',
                'error_code' => $e->errorCode,
                'message' => collect($e->errors())->flatten()->first(),
            ], 422);
        }

        return response()->json(['status' => 'success', 'data' => $estimate]);
    }

    /**
     * Initier un virement bancaire
     *
     * Débite le solde (montant + frais) puis route le virement : traitement manuel par un
     * agent tant qu'aucun provider bancaire automatique n'est configuré pour le pays.
     * L'en-tête `Idempotency-Key` rend la requête rejouable sans double débit.
     */
    public function store(BankTransferRequest $request): JsonResponse
    {
        try {
            $result = $this->transactions->createBankTransfer(
                $request->user(),
                $request->only(['country', 'amount', 'beneficiary']),
                $request->header('Idempotency-Key'),
            );
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => collect($e->errors())->flatten()->first(),
            ], 400);
        } catch (\Exception $e) {
            logger($e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while processing your request. Please try again.',
            ], 500);
        }

        $transaction = $result['transaction'];

        return response()->json([
            'status' => 'success',
            'message' => 'Request accepted, processing in progress',
            'amount' => (float) $transaction->amount_sent,
            'fee_charged' => $result['fee'],
            'total' => $result['total'],
            'currency' => $transaction->currency_sent,
            'amount_received' => (float) $transaction->amount_to_receive,
            'currency_received' => $transaction->currency_received,
            'exchange_rate' => (float) $transaction->exchange_rate,
            'remaining_balance' => $result['balance'],
            'request_id' => $transaction->reference,
            'processing_mode' => $transaction->processing_mode->value,
            'transfer_status' => $transaction->status,
        ]);
    }
}
