<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Exceptions\TransactionValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\BankTransferRequest;
use App\Services\Banking\BankCountryCatalog;
use App\Services\TransactionService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Virements bancaires de l'API gateway B2B. Le suivi passe par GET /transactions/{reference}
 * (même contrat que les autres opérations).
 */
#[Group('Merchant Gateway', weight: 1)]
class BankTransferController extends Controller
{
    public function __construct(private readonly TransactionService $transactions)
    {
    }

    /**
     * Lister les pays disponibles pour un virement bancaire
     *
     * Pays où le virement bancaire est ouvert, avec leur devise et les champs du bénéficiaire
     * obligatoires pour ce pays (`required_fields`). Les autres champs (`full_name`, `phone`,
     * `email`, `bank_name`, `bank_code`, `branch_code`, `account_number`, `iban`, `swift_bic`,
     * `address`, `city`) sont acceptés mais facultatifs.
     */
    public function countries(BankCountryCatalog $catalog): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $catalog->available()->map(fn (array $c) => collect($c)->except(['id', 'flag']))->values(),
        ], 200);
    }

    /**
     * Initier un virement bancaire
     *
     * Le montant (`amount`) est exprimé dans la devise du wallet du marchand, frais en plus ; les
     * coordonnées du bénéficiaire sont dans `beneficiary` (champs obligatoires selon le pays).
     * Débite immédiatement le solde du marchand (montant + frais) puis route le virement.
     * Tant qu'aucun provider bancaire automatique n'est branché pour le pays, le virement est
     * traité manuellement par un agent : la réponse porte `processing_mode: MANUAL` et
     * `status: pending_manual_review`, puis `assigned` → `processing` → `success`. S'il est
     * rejeté (`rejected`) ou en échec (`failed`), le solde est remboursé. Nécessite l'en-tête
     * `Idempotency-Key` et le scope `bank_transfer.write`.
     *
     * **Sandbox** : le résultat dépend de la fin du numéro de compte du bénéficiaire
     * (`beneficiary.account_number`) : `...0002` → échec (remboursé), `...0003` → reste en
     * cours, tout autre numéro → succès. Un virement sandbox n'est jamais envoyé à un agent.
     */
    #[HeaderParameter(
        'Idempotency-Key',
        description: 'Clé unique choisie par le marchand pour rendre la requête rejouable sans risque de double exécution (ex: un UUID v4). Un retry avec la même clé et le même corps renvoie la réponse d\'origine.',
        required: true,
        type: 'string',
        example: '550e8400-e29b-41d4-a716-446655440000'
    )]
    #[BodyParameter('country', description: 'Code ISO (ex: "SN") ou nom du pays de destination — voir GET /bank-countries.', type: 'string', example: 'SN')]
    public function store(BankTransferRequest $request): JsonResponse
    {
        try {
            $result = $this->transactions->createBankTransfer(
                $request->user(),
                $request->only(['country', 'amount', 'beneficiary']),
                null,
                'merchant_api',
                $this->environment($request),
            );
        } catch (TransactionValidationException $e) {
            return response()->json([
                'status' => 'error',
                'error_code' => $e->errorCode,
                'message' => collect($e->errors())->flatten()->first(),
            ], 422);
        } catch (\Exception $e) {
            logger($e->getMessage());

            return response()->json([
                'status' => 'error',
                'error_code' => 'PROCESSING_ERROR',
                'message' => 'An error occurred while processing your request. Please try again.',
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Request accepted, processing in progress',
            'data' => $result['transaction']->load('bankBeneficiary')->toMerchantArray([
                'fee' => (float) $result['fee'],
                'total_debited' => (float) $result['total'],
                'remaining_balance' => (float) $result['balance'],
            ]),
        ], 200);
    }

    /**
     * Environnement de la clé API (posé par ApiKeyAuth) : tout ce qui n'est pas explicitement
     * 'production' est traité comme sandbox.
     */
    private function environment(Request $request): string
    {
        return $request->attributes->get('environment') === 'production' ? 'production' : 'sandbox';
    }
}
