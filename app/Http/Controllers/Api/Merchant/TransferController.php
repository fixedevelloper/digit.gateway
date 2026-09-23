<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Exceptions\TransactionValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\DepositRequest;
use App\Http\Requests\Merchant\TransferRequest;
use App\Http\Requests\Merchant\WithdrawalRequest;
use App\Models\Agency;
use App\Models\Transaction;
use App\Services\TransactionService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Endpoints transactionnels de l'API gateway B2B (/v1/gateway/*), authentifiés
 * par clé API. Contrat volontairement différent de Api\TransferController
 * (app mobile) : enveloppe de réponse enrichie pour l'intégration
 * serveur-à-serveur (référence, frais détaillés, `error_code` machine-readable),
 * pas de PIN, idempotence par en-tête plutôt que par fenêtre temporelle.
 *
 * La logique de débit/verrouillage du wallet reste partagée avec l'app mobile
 * via TransactionService — seule la façade HTTP diffère.
 */
#[Group('Merchant Gateway', 'Endpoints B2B authentifiés par clé API (Authorization: Bearer sk_test_.../sk_live_...), pour une intégration serveur-à-serveur. Voir /api/merchants/register pour obtenir une clé.', weight: 1)]
class TransferController extends Controller
{
    public function __construct(private readonly TransactionService $transactions)
    {
    }

    /**
     * Initier un transfert d'argent
     *
     * Débite immédiatement le solde du marchand puis met l'envoi vers l'opérateur
     * mobile money en file d'attente. Nécessite l'en-tête `Idempotency-Key`.
     */
    #[HeaderParameter(
        'Idempotency-Key',
        description: 'Clé unique choisie par le marchand pour rendre la requête rejouable sans risque de double exécution (ex: un UUID v4). Un retry avec la même clé et le même corps renvoie la réponse d\'origine.',
        required: true,
        type: 'string',
        example: '550e8400-e29b-41d4-a716-446655440000'
    )]
    #[BodyParameter('country', description: 'Nom du pays (ex: "Republic of Congo") ou code ISO (ex: "CG") — voir GET /countries.', type: 'string', example: 'CG')]
    #[BodyParameter('carrier', description: 'Code opérateur retourné par GET /countries (carriers[].code), ex: "MTN_CG".', type: 'string', example: 'MTN_CG')]
    #[BodyParameter('currency', description: 'Devise de l\'opérateur (carriers[].currency), requise seulement si le même code opérateur existe en plusieurs devises dans le pays.', type: 'string', example: 'USD')]
    #[BodyParameter('quote_id', description: 'Identifiant de cotation renvoyé par POST /quotes. Obligatoire quand la devise de l\'opérateur diffère de celle du wallet (ex: wallet XAF, opérateur USD).', type: 'string', example: '9d3f1c2e-7a4b-4c1d-9e2f-1a2b3c4d5e6f')]
    public function initiateTransfer(TransferRequest $request): JsonResponse
    {
        try {
            $result = $this->transactions->createTransfer(
                $request->user(),
                $request->only(['country', 'carrier', 'currency', 'operator_id', 'quote_id', 'number', 'amount']),
                'merchant_api'
            );
        } catch (TransactionValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            logger($e->getMessage());

            return $this->processingErrorResponse();
        }

        return $this->successResponse($result);
    }

    /**
     * Initier un retrait (cash-out)
     *
     * Vérifie l'agence de retrait via son code (`agensic_code`), débite le solde du
     * marchand puis met la demande en file d'attente. Nécessite l'en-tête
     * `Idempotency-Key`.
     */
    #[HeaderParameter(
        'Idempotency-Key',
        description: 'Clé unique choisie par le marchand pour rendre la requête rejouable sans risque de double exécution (ex: un UUID v4). Un retry avec la même clé et le même corps renvoie la réponse d\'origine.',
        required: true,
        type: 'string',
        example: '550e8400-e29b-41d4-a716-446655440000'
    )]
    #[BodyParameter('country', description: 'Nom du pays (ex: "Republic of Congo") ou code ISO (ex: "CG") — voir GET /countries.', type: 'string', example: 'CG')]
    #[BodyParameter('carrier', description: 'Code opérateur retourné par GET /countries (carriers[].code), ex: "MTN_CG".', type: 'string', example: 'MTN_CG')]
    #[BodyParameter('currency', description: 'Devise de l\'opérateur (carriers[].currency), requise seulement si le même code opérateur existe en plusieurs devises dans le pays.', type: 'string', example: 'USD')]
    #[BodyParameter('quote_id', description: 'Identifiant de cotation renvoyé par POST /quotes. Obligatoire quand la devise de l\'opérateur diffère de celle du wallet (ex: wallet XAF, opérateur USD).', type: 'string', example: '9d3f1c2e-7a4b-4c1d-9e2f-1a2b3c4d5e6f')]
    public function initiateWithdrawal(WithdrawalRequest $request): JsonResponse
    {
        $agency = Agency::where('code', $request->agensic_code)
            ->where('status', 'active')
            ->first();

        if (! $agency) {
            return response()->json([
                'status' => 'error',
                'error_code' => 'INVALID_AGENCY_CODE',
                'message' => "Le code d'agence fourni est invalide ou l'agence n'est pas disponible.",
            ], 422);
        }

        try {
            $result = $this->transactions->createWithdrawal(
                $request->user(),
                $agency,
                $request->only(['country', 'carrier', 'currency', 'operator_id', 'quote_id', 'number', 'amount']),
                'merchant_api'
            );
        } catch (TransactionValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            Log::error('Erreur retrait marchand : '.$e->getMessage());

            return $this->processingErrorResponse();
        }

        return $this->successResponse($result);
    }

    /**
     * Initier un dépôt (cash-in)
     *
     * Enregistre une demande de dépôt (crédit du solde) et la met en file d'attente.
     * Nécessite l'en-tête `Idempotency-Key`.
     */
    #[HeaderParameter(
        'Idempotency-Key',
        description: 'Clé unique choisie par le marchand pour rendre la requête rejouable sans risque de double exécution (ex: un UUID v4). Un retry avec la même clé et le même corps renvoie la réponse d\'origine.',
        required: true,
        type: 'string',
        example: '550e8400-e29b-41d4-a716-446655440000'
    )]
    #[BodyParameter('country', description: 'Nom du pays (ex: "Republic of Congo") ou code ISO (ex: "CG") — voir GET /countries.', type: 'string', example: 'CG')]
    #[BodyParameter('carrier', description: 'Code opérateur retourné par GET /countries (carriers[].code), ex: "MTN_CG".', type: 'string', example: 'MTN_CG')]
    #[BodyParameter('currency', description: 'Devise de l\'opérateur (carriers[].currency), requise seulement si le même code opérateur existe en plusieurs devises dans le pays.', type: 'string', example: 'USD')]
    #[BodyParameter('quote_id', description: 'Identifiant de cotation renvoyé par POST /quotes. Obligatoire quand la devise de l\'opérateur diffère de celle du wallet (ex: wallet XAF, opérateur USD).', type: 'string', example: '9d3f1c2e-7a4b-4c1d-9e2f-1a2b3c4d5e6f')]
    public function initiateDeposit(DepositRequest $request): JsonResponse
    {
        try {
            $result = $this->transactions->createDeposit(
                $request->user(),
                $request->only(['country', 'carrier', 'currency', 'operator_id', 'quote_id', 'number', 'amount']),
                'merchant_api'
            );
        } catch (TransactionValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            logger($e->getMessage());

            return $this->processingErrorResponse();
        }

        return $this->successResponse($result);
    }

    /**
     * Lister les transactions du marchand
     *
     * Historique paginé (20 par page) des transactions créées via l'API gateway,
     * filtrable par type, statut et plage de dates.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['sometimes', Rule::in(['transfer', 'withdrawal', 'deposit', 'payment'])],
            'status' => ['sometimes', Rule::in(['pending', 'processing', 'success', 'failed', 'reversed'])],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Transaction::where('user_id', $request->user()->id)
            ->where('channel', 'merchant_api');

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        $transactions = $query->latest()->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'status' => 'success',
            'data' => $transactions->getCollection()->map(fn (Transaction $t) => $this->formatTransaction($t)),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ], 200);
    }

    /**
     * Détail et statut d'une transaction
     *
     * Retourne l'état courant (`pending`, `processing`, `success`, `failed`,
     * `reversed`) et le détail complet d'une transaction du marchand, identifiée
     * par sa référence.
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        $transaction = Transaction::where('user_id', $request->user()->id)
            ->where('channel', 'merchant_api')
            ->where('reference', $reference)
            ->first();

        if (! $transaction) {
            return response()->json([
                'status' => 'error',
                'error_code' => 'TRANSACTION_NOT_FOUND',
                'message' => 'Transaction introuvable.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->formatTransaction($transaction),
        ], 200);
    }

    private function successResponse(array $result): JsonResponse
    {
        $transaction = $result['transaction'];

        return response()->json([
            'status' => 'success',
            'message' => 'Request accepted, processing in progress',
            'data' => $this->formatTransaction($transaction, [
                'fee' => (float) $result['fee'],
                'total_debited' => (float) $result['total'],
                'remaining_balance' => (float) $result['balance'],
            ]),
        ], 200);
    }

    private function formatTransaction(Transaction $transaction, array $extra = []): array
    {
        return array_merge([
            'reference' => $transaction->reference,
            'type' => $transaction->type,
            'status' => $transaction->status,
            'amount' => (float) $transaction->amount_sent,
            'fee' => (float) $transaction->fees,
            'currency' => $transaction->currency_sent,
            'amount_received' => (float) $transaction->amount_to_receive,
            'currency_received' => $transaction->currency_received,
            'exchange_rate' => (float) $transaction->exchange_rate,
            'recipient' => [
                'phone' => $transaction->recipient_phone,
                'operator' => $transaction->recipient_operator,
                'country' => $transaction->country_name,
            ],
            'failure_reason' => $transaction->failure_reason,
            'created_at' => $transaction->created_at?->toIso8601String(),
            'updated_at' => $transaction->updated_at?->toIso8601String(),
        ], $extra);
    }

    private function validationErrorResponse(TransactionValidationException $e): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'error_code' => $e->errorCode,
            'message' => collect($e->errors())->flatten()->first(),
        ], 422);
    }

    private function processingErrorResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'error_code' => 'PROCESSING_ERROR',
            'message' => 'An error occurred while processing your request. Please try again.',
        ], 500);
    }
}
