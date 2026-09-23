<?php

namespace App\Services;

use App\Exceptions\TransactionValidationException;
use App\Jobs\ProcessTransferJob;
use App\Jobs\ProcessWithdrawalJob;
use App\Models\Agency;
use App\Models\Operator;
use App\Models\Quote;
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
 *
 * Conversion de devises : le client saisit toujours son montant dans la devise
 * de son wallet (ex: XAF). Quand l'opérateur choisi travaille dans une autre
 * devise (ex: USD), le montant est converti au taux manuel de l'admin : le wallet
 * est débité en XAF, Digitwave reçoit l'équivalent en USD. Dans ce cas une
 * cotation (createQuote) est obligatoire, pour que le client valide le montant
 * converti et que le taux appliqué soit exactement celui qu'il a vu.
 */
class TransactionService
{
    public function __construct(private readonly ExchangeRateService $exchangeRates)
    {
    }

    /**
     * Résout l'opérateur actif visé par la requête, soit par son id (`operator_id`,
     * non ambigu), soit par pays + code (`country`, `carrier`) et éventuellement la
     * devise de l'opérateur (`currency`) quand un même code existe en plusieurs devises.
     *
     * Le pays est accepté par nom exact (ex: "Republic of Congo", valeur historiquement
     * envoyée par l'app mobile) ou par code ISO (ex: "CG", la valeur mise en avant par
     * GET /v1/gateway/countries pour les intégrations marchandes).
     */
    private function resolveOperator(array $data): Operator
    {
        $activeCountry = fn ($q) => $q->where('status', true);

        if (! empty($data['operator_id'])) {
            $operator = Operator::whereKey($data['operator_id'])
                ->where('status', true)
                ->whereHas('country', $activeCountry)
                ->first();

            if (! $operator) {
                throw TransactionValidationException::make(
                    'INVALID_OPERATOR',
                    'operator_id',
                    'Opérateur non supporté ou indisponible.'
                );
            }

            return $operator;
        }

        $countryName = (string) ($data['country'] ?? '');
        $carrierCode = (string) ($data['carrier'] ?? '');

        $operators = Operator::whereHas('country', function ($q) use ($countryName, $activeCountry) {
            $activeCountry($q);
            $q->where(function ($q2) use ($countryName) {
                $q2->where('name', $countryName)
                    ->orWhere('iso', strtoupper($countryName));
            });
        })
            ->where('code', $carrierCode)
            ->where('status', true)
            ->when(! empty($data['currency']), fn ($q) => $q->where('currency', strtoupper($data['currency'])))
            ->get();

        if ($operators->isEmpty()) {
            throw TransactionValidationException::make(
                'INVALID_OPERATOR',
                'carrier',
                "Opérateur « {$carrierCode} » non supporté ou indisponible pour « {$countryName} »."
            );
        }

        if ($operators->count() > 1) {
            $currencies = $operators->pluck('currency')->implode(', ');

            throw TransactionValidationException::make(
                'AMBIGUOUS_OPERATOR',
                'carrier',
                "L'opérateur « {$carrierCode} » existe en plusieurs devises ({$currencies}) pour « {$countryName} » : précisez `currency` ou `operator_id`."
            );
        }

        return $operators->first();
    }

    /**
     * Vérifie que le montant (dans la devise de l'opérateur) respecte les bornes
     * min/max configurées pour cet opérateur.
     */
    private function assertWithinBounds(Operator $operator, float $amount): void
    {
        if ($amount < (float) $operator->min_amount || $amount > (float) $operator->max_amount) {
            throw TransactionValidationException::make(
                'AMOUNT_OUT_OF_BOUNDS',
                'amount',
                "Le montant doit être compris entre {$operator->min_amount} et {$operator->max_amount} {$operator->currency} pour cet opérateur."
            );
        }
    }

    /**
     * Calcule le frais réel configuré pour l'opérateur (frais fixe + pourcentage du montant),
     * dans la devise de l'opérateur.
     */
    private function computeFee(Operator $operator, float $amount): float
    {
        return round((float) $operator->fixed_fee + ($amount * (float) $operator->percent_fee), 2);
    }

    /**
     * Calcule la ventilation financière d'une opération. `amount`/`fee`/`total` sont
     * dans la devise du wallet (ce que le client saisit et paie), `converted_*` dans
     * celle de l'opérateur (ce qui part vers Digitwave), `rate` = unités de devise
     * wallet pour 1 unité de devise opérateur (ex: 605 XAF pour 1 USD).
     *
     * Les frais et les bornes min/max sont définis dans la devise de l'opérateur.
     *
     * @return array{amount: float, fee: float, total: float, currency: string, converted_amount: float, converted_fee: float, converted_currency: string, rate: float}
     */
    private function price(Operator $operator, string $walletCurrency, float $amount, string $type): array
    {
        if ($operator->currency === $walletCurrency) {
            $this->assertWithinBounds($operator, $amount);
            $fee = $this->computeFee($operator, $amount);

            return [
                'amount' => $amount,
                'fee' => $fee,
                'total' => $amount + $fee,
                'currency' => $walletCurrency,
                'converted_amount' => $amount,
                'converted_fee' => $fee,
                'converted_currency' => $walletCurrency,
                'rate' => 1.0,
            ];
        }

        $rate = $this->exchangeRates->rate($operator->currency, $walletCurrency);

        // Montant versé (transfert/retrait) : arrondi inférieur, on ne verse jamais plus
        // que la contre-valeur payée. Montant encaissé (dépôt) : arrondi supérieur.
        $converted = $type === 'deposit'
            ? $this->exchangeRates->ceil($amount / $rate, $operator->currency)
            : $this->exchangeRates->floor($amount / $rate, $operator->currency);

        $this->assertWithinBounds($operator, $converted);

        $convertedFee = $this->computeFee($operator, $converted);
        $fee = $this->exchangeRates->ceil($convertedFee * $rate, $walletCurrency);

        return [
            'amount' => $amount,
            'fee' => $fee,
            'total' => $amount + $fee,
            'currency' => $walletCurrency,
            'converted_amount' => $converted,
            'converted_fee' => $convertedFee,
            'converted_currency' => $operator->currency,
            'rate' => $rate,
        ];
    }

    /**
     * Crée une cotation figée pendant `exchange.quote_ttl` secondes : le client voit
     * l'équivalent dans la devise de l'opérateur, les frais et le total débité, puis
     * valide l'opération en renvoyant `quote_id`.
     *
     * @param  array{operator_id?:int,country?:string,carrier?:string,currency?:string,amount:float|string}  $data
     *
     * @throws ValidationException si l'opérateur est invalide/hors bornes ou le taux indisponible
     */
    public function createQuote(User $user, array $data, string $type): Quote
    {
        $operator = $this->resolveOperator($data);
        $pricing = $this->price($operator, $user->wallet->currency, (float) $data['amount'], $type);

        return Quote::create(array_merge($pricing, [
            'user_id' => $user->id,
            'operator_id' => $operator->id,
            'type' => $type,
            'expires_at' => now()->addSeconds(config('exchange.quote_ttl')),
        ]))->setRelation('operator', $operator);
    }

    /**
     * Détermine l'opérateur et la ventilation financière d'une opération, soit à partir
     * d'une cotation validée par le client (consommée ici, sous verrou), soit calculée
     * à la volée quand aucune conversion n'est nécessaire. Doit être appelé dans une
     * transaction DB.
     *
     * @return array{operator: Operator, pricing: array, quote: ?Quote}
     */
    private function prepare(User $user, string $walletCurrency, array $data, string $type): array
    {
        $amount = (float) $data['amount'];

        if (! empty($data['quote_id'])) {
            $quote = $this->consumeQuote($user, (string) $data['quote_id'], $type, $amount, $walletCurrency);

            return [
                'operator' => $quote->operator,
                'pricing' => $quote->only(['amount', 'fee', 'total', 'currency', 'converted_amount', 'converted_fee', 'converted_currency', 'rate']),
                'quote' => $quote,
            ];
        }

        $operator = $this->resolveOperator($data);

        if ($operator->currency !== $walletCurrency) {
            throw TransactionValidationException::make(
                'QUOTE_REQUIRED',
                'quote_id',
                "Cet opérateur travaille en {$operator->currency} : demandez d'abord une cotation (POST /quote) et validez-la avec son quote_id."
            );
        }

        return ['operator' => $operator, 'pricing' => $this->price($operator, $walletCurrency, $amount, $type), 'quote' => null];
    }

    /**
     * Verrouille et consomme une cotation : elle doit appartenir à l'utilisateur, viser
     * le même type d'opération et le même montant, ne pas être expirée ni déjà utilisée.
     */
    private function consumeQuote(User $user, string $quoteId, string $type, float $amount, string $walletCurrency): Quote
    {
        $quote = Str::isUuid($quoteId)
            ? Quote::whereKey($quoteId)->where('user_id', $user->id)->lockForUpdate()->first()
            : null;

        if (! $quote || $quote->type !== $type || $quote->currency !== $walletCurrency) {
            throw TransactionValidationException::make('INVALID_QUOTE', 'quote_id', 'Cotation introuvable.');
        }

        if ($quote->used_at) {
            throw TransactionValidationException::make('QUOTE_ALREADY_USED', 'quote_id', 'Cette cotation a déjà été utilisée.');
        }

        if ($quote->expires_at->isPast()) {
            throw TransactionValidationException::make('QUOTE_EXPIRED', 'quote_id', 'Cette cotation a expiré, veuillez en demander une nouvelle.');
        }

        if (abs($quote->amount - $amount) >= 0.01) {
            throw TransactionValidationException::make('QUOTE_MISMATCH', 'amount', 'Le montant ne correspond pas à la cotation validée.');
        }

        if (! $quote->operator || ! $quote->operator->status) {
            throw TransactionValidationException::make('INVALID_OPERATOR', 'quote_id', 'Opérateur non supporté ou indisponible.');
        }

        $quote->update(['used_at' => now()]);

        return $quote;
    }

    /**
     * Attributs communs à toutes les transactions, dérivés de l'opérateur résolu et de
     * la ventilation financière. `amount_to_receive` est toujours le montant envoyé à
     * Digitwave, dans la devise de l'opérateur (`currency_received`).
     */
    private function transactionAttributes(array $prepared, array $data, string $type): array
    {
        ['operator' => $operator, 'pricing' => $pricing, 'quote' => $quote] = $prepared;

        return [
            'type' => $type,
            'recipient_phone' => $data['number'],
            'recipient_operator' => $operator->code,
            'operator_id' => $operator->id,
            'quote_id' => $quote?->id,
            'country_name' => $data['country'] ?? $operator->country->name,
            'amount_sent' => $pricing['amount'],
            'currency_sent' => $pricing['currency'],
            'fees' => $pricing['fee'],
            'exchange_rate' => $pricing['rate'],
            // Dépôt : Digitwave collecte le montant + les frais auprès du client.
            'amount_to_receive' => $type === 'deposit'
                ? $pricing['converted_amount'] + $pricing['converted_fee']
                : $pricing['converted_amount'],
            'currency_received' => $pricing['converted_currency'],
        ];
    }

    /**
     * Initie un transfert : débit immédiat du wallet (verrouillé pendant la transaction
     * pour empêcher une double dépense), puis traitement asynchrone via ProcessTransferJob.
     *
     * @param  array{country?:string,carrier?:string,currency?:string,operator_id?:int,quote_id?:string,number:string,amount:float|string}  $data
     * @return array{transaction: Transaction, balance: float, fee: float, total: float}
     *
     * @throws ValidationException si l'opérateur est invalide/hors bornes ou le solde insuffisant
     */
    public function createTransfer(User $user, array $data, string $channel = 'mobile_app'): array
    {
        $requestId = 'TX-'.strtoupper(Str::random(12));

        $result = DB::transaction(function () use ($user, $requestId, $data, $channel) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $prepared = $this->prepare($user, $wallet->currency, $data, 'transfer');
            $pricing = $prepared['pricing'];

            if ($wallet->balance < $pricing['total']) {
                throw TransactionValidationException::make('INSUFFICIENT_FUNDS', 'amount', 'Insufficient fund/Balance.');
            }

            $wallet->decrement('balance', $pricing['total']);

            $transaction = Transaction::create(array_merge($this->transactionAttributes($prepared, $data, 'transfer'), [
                'reference' => $requestId,
                'user_id' => $user->id,
                'channel' => $channel,
                'status' => 'processing',
            ]));

            return ['transaction' => $transaction, 'balance' => $wallet->balance, 'fee' => $pricing['fee'], 'total' => $pricing['total']];
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
     * @param  array{country?:string,carrier?:string,currency?:string,operator_id?:int,quote_id?:string,number:string,amount:float|string}  $data
     * @return array{transaction: Transaction, balance: float, fee: float, total: float}
     *
     * @throws ValidationException si l'opérateur est invalide/hors bornes ou le solde insuffisant
     */
    public function createWithdrawal(User $user, Agency $agency, array $data, string $channel = 'mobile_app'): array
    {
        $requestId = 'WD-'.strtoupper(Str::random(12));

        $result = DB::transaction(function () use ($user, $agency, $requestId, $data, $channel) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $prepared = $this->prepare($user, $wallet->currency, $data, 'withdrawal');
            $pricing = $prepared['pricing'];

            if ($wallet->balance < $pricing['total']) {
                throw TransactionValidationException::make('INSUFFICIENT_FUNDS', 'amount', 'Solde insuffisant pour effectuer ce retrait.');
            }

            $wallet->decrement('balance', $pricing['total']);

            $transaction = Transaction::create(array_merge($this->transactionAttributes($prepared, $data, 'withdrawal'), [
                'reference' => $requestId,
                'user_id' => $user->id,
                'channel' => $channel,
                'agency_id' => $agency->id,
                'status' => 'pending',
            ]));

            return ['transaction' => $transaction, 'balance' => $wallet->balance, 'fee' => $pricing['fee'], 'total' => $pricing['total']];
        });

        ProcessTransferJob::dispatch($result['transaction']);

        return $result;
    }

    /**
     * Enregistre une demande de dépôt (cash-in) : aucune écriture sur le wallet à ce
     * stade (le crédit de `amount_sent` intervient à la confirmation) ; on se contente
     * de valider l'opérateur et de tracer la transaction en `pending`. Digitwave
     * collecte `amount_to_receive` (montant + frais) dans la devise de l'opérateur.
     *
     * @param  array{country?:string,carrier?:string,currency?:string,operator_id?:int,quote_id?:string,number:string,amount:float|string}  $data
     * @return array{transaction: Transaction, balance: float, fee: float, total: float}
     *
     * @throws ValidationException si l'opérateur est invalide ou hors bornes
     */
    public function createDeposit(User $user, array $data, string $channel = 'mobile_app'): array
    {
        $wallet = $user->wallet;
        $requestId = 'WD-'.strtoupper(Str::random(12));

        // Une erreur (opérateur, cotation) annule tout : rien n'est écrit.
        [$transaction, $pricing] = DB::transaction(function () use ($user, $wallet, $requestId, $data, $channel) {
            $prepared = $this->prepare($user, $wallet->currency, $data, 'deposit');

            $transaction = Transaction::create(array_merge($this->transactionAttributes($prepared, $data, 'deposit'), [
                'reference' => $requestId,
                'user_id' => $user->id,
                'channel' => $channel,
                'status' => 'pending',
            ]));

            return [$transaction, $prepared['pricing']];
        });

        ProcessWithdrawalJob::dispatch($transaction);

        return ['transaction' => $transaction, 'balance' => (float) $wallet->balance, 'fee' => $pricing['fee'], 'total' => $pricing['total']];
    }
}
