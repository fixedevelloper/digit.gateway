<?php

namespace App\Services;

use App\Exceptions\TransactionValidationException;
use App\Jobs\ProcessTransferJob;
use App\Jobs\ProcessWithdrawalJob;
use App\Models\Agency;
use App\Models\Operator;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Logique de mouvement d'argent partagée entre les deux façades HTTP :
 * Api\TransferController (app mobile, Sanctum) et Api\Merchant\TransferController
 * (intégrations B2B, clé API). Les deux appellent exactement ce code pour
 * résoudre l'opérateur, calculer les frais et débiter le wallet — il ne doit
 * jamais y avoir deux implémentations divergentes de cette partie critique.
 *
 * Chaque contrôleur reste responsable de la validation d'entrée (Form Request
 * propre à son canal) et du formatage de la réponse HTTP (les deux façades
 * ont des contrats différents).
 */
class TransactionService
{
    /**
     * Résout l'opérateur actif correspondant au pays et au code transmis, et vérifie
     * que le montant demandé respecte les bornes min/max configurées pour cet opérateur.
     * Lève une ValidationException si l'opérateur est introuvable/inactif ou si le
     * montant est hors bornes.
     *
     * Le pays est accepté par nom exact (ex: "Republic of Congo", valeur historiquement
     * envoyée par l'app mobile) ou par code ISO (ex: "CG", la valeur mise en avant par
     * GET /v1/gateway/countries pour les intégrations marchandes).
     */
    private function resolveOperator(string $countryName, string $carrierCode, float $amount): Operator
    {
        $operator = Operator::whereHas('country', function ($q) use ($countryName) {
            $q->where('status', true)
                ->where(function ($q2) use ($countryName) {
                    $q2->where('name', $countryName)
                        ->orWhere('iso', strtoupper($countryName));
                });
        })
            ->where('code', $carrierCode)
            ->where('status', true)
            ->first();

        if (! $operator) {
            throw TransactionValidationException::make(
                'INVALID_OPERATOR',
                'carrier',
                "Opérateur « {$carrierCode} » non supporté ou indisponible pour « {$countryName} »."
            );
        }

        if ($amount < (float) $operator->min_amount || $amount > (float) $operator->max_amount) {
            throw TransactionValidationException::make(
                'AMOUNT_OUT_OF_BOUNDS',
                'amount',
                "Le montant doit être compris entre {$operator->min_amount} et {$operator->max_amount} pour cet opérateur."
            );
        }

        return $operator;
    }

    /**
     * Calcule le frais réel configuré pour l'opérateur (frais fixe + pourcentage du montant).
     */
    private function computeFee(Operator $operator, float $amount): float
    {
        return round((float) $operator->fixed_fee + ($amount * (float) $operator->percent_fee), 2);
    }

    /**
     * Initie un transfert : débit immédiat du wallet (verrouillé pendant la transaction
     * pour empêcher une double dépense), puis traitement asynchrone via ProcessTransferJob.
     *
     * @param  array{country:string,carrier:string,number:string,amount:float|string}  $data
     * @return array{transaction: Transaction, balance: float, fee: float, total: float}
     *
     * @throws ValidationException si l'opérateur est invalide/hors bornes ou le solde insuffisant
     */
    public function createTransfer(User $user, array $data, string $channel = 'mobile_app'): array
    {
        $amount = (float) $data['amount'];
        $requestId = 'TX-'.strtoupper(Str::random(12));

        $result = DB::transaction(function () use ($user, $amount, $requestId, $data, $channel) {
            $operator = $this->resolveOperator($data['country'], $data['carrier'], $amount);
            $feeCharged = $this->computeFee($operator, $amount);
            $totalDeduction = $amount + $feeCharged;

            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            if ($wallet->balance < $totalDeduction) {
                throw TransactionValidationException::make('INSUFFICIENT_FUNDS', 'amount', 'Insufficient fund/Balance.');
            }

            $wallet->decrement('balance', $totalDeduction);

            $transaction = Transaction::create([
                'reference' => $requestId,
                'user_id' => $user->id,
                'channel' => $channel,
                'recipient_phone' => $data['number'],
                'recipient_operator' => $data['carrier'],
                'amount_sent' => $amount,
                'country_name' => $data['country'],
                'currency_sent' => $wallet->currency,
                'fees' => $feeCharged,
                'amount_to_receive' => $amount,
                'currency_received' => 'XAF',
                'status' => 'processing',
                'type' => 'transfer',
            ]);

            return ['transaction' => $transaction, 'balance' => $wallet->balance, 'fee' => $feeCharged, 'total' => $totalDeduction];
        });

        // Dispatché après commit (hors de la transaction DB) pour ne jamais traiter
        // une transaction dont l'écriture n'a pas encore été validée.
        ProcessTransferJob::dispatch($result['transaction']);

        return $result;
    }

    /**
     * Initie un retrait (cash-out) : débit immédiat du wallet (mêmes garanties que
     * createTransfer), rattaché à l'agence de retrait déjà résolue par l'appelant.
     *
     * @param  array{country:string,carrier:string,number:string,amount:float|string}  $data
     * @return array{transaction: Transaction, balance: float, fee: float, total: float}
     *
     * @throws ValidationException si l'opérateur est invalide/hors bornes ou le solde insuffisant
     */
    public function createWithdrawal(User $user, Agency $agency, array $data, string $channel = 'mobile_app'): array
    {
        $amount = (float) $data['amount'];
        $requestId = 'WD-'.strtoupper(Str::random(12));

        $result = DB::transaction(function () use ($user, $agency, $amount, $requestId, $data, $channel) {
            $operator = $this->resolveOperator($data['country'], $data['carrier'], $amount);
            $feeCharged = $this->computeFee($operator, $amount);
            $totalDeduction = $amount + $feeCharged;

            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            if ($wallet->balance < $totalDeduction) {
                throw TransactionValidationException::make('INSUFFICIENT_FUNDS', 'amount', 'Solde insuffisant pour effectuer ce retrait.');
            }

            $wallet->decrement('balance', $totalDeduction);

            $transaction = Transaction::create([
                'reference' => $requestId,
                'user_id' => $user->id,
                'channel' => $channel,
                'agency_id' => $agency->id,
                'recipient_phone' => $data['number'],
                'recipient_operator' => $data['carrier'],
                'country_name' => $data['country'],
                'amount_sent' => $amount,
                'fees' => $feeCharged,
                'amount_to_receive' => $amount,
                'currency_sent' => $wallet->currency,
                'currency_received' => $wallet->currency,
                'status' => 'pending',
                'type' => 'withdrawal',
            ]);

            return ['transaction' => $transaction, 'balance' => $wallet->balance, 'fee' => $feeCharged, 'total' => $totalDeduction];
        });

        ProcessTransferJob::dispatch($result['transaction']);

        return $result;
    }

    /**
     * Enregistre une demande de dépôt (cash-in) : aucune écriture sur le wallet à ce
     * stade (le crédit intervient à la confirmation, via ProcessWithdrawalJob) ; on
     * se contente de valider l'opérateur et de tracer la transaction en `pending`.
     *
     * @param  array{country:string,carrier:string,number:string,amount:float|string}  $data
     * @return array{transaction: Transaction, balance: float, fee: float, total: float}
     *
     * @throws ValidationException si l'opérateur est invalide ou hors bornes
     */
    public function createDeposit(User $user, array $data, string $channel = 'mobile_app'): array
    {
        $wallet = $user->wallet;
        $amount = (float) $data['amount'];
        $requestId = 'WD-'.strtoupper(Str::random(12));

        // Résolu hors transaction DB : une erreur ici ne doit rien écrire.
        $operator = $this->resolveOperator($data['country'], $data['carrier'], $amount);
        $feeCharged = $this->computeFee($operator, $amount);
        $totalDeduction = $amount + $feeCharged;

        $transaction = DB::transaction(function () use ($user, $wallet, $amount, $requestId, $data, $feeCharged, $totalDeduction, $channel) {
            return Transaction::create([
                'reference' => $requestId,
                'user_id' => $user->id,
                'channel' => $channel,
                'recipient_phone' => $data['number'],
                'recipient_operator' => $data['carrier'],
                'country_name' => $data['country'],
                'amount_sent' => $amount,
                'fees' => $feeCharged,
                'amount_to_receive' => $totalDeduction,
                'currency_sent' => $wallet->currency,
                'currency_received' => $wallet->currency,
                'status' => 'pending',
                'type' => 'deposit',
            ]);
        });

        ProcessWithdrawalJob::dispatch($transaction);

        return ['transaction' => $transaction, 'balance' => (float) $wallet->balance, 'fee' => $feeCharged, 'total' => $totalDeduction];
    }
}
