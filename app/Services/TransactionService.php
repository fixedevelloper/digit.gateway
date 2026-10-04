<?php

namespace App\Services;

use App\Enums\ProcessingMode;
use App\Events\TransferCreated;
use App\Enums\TransferService;
use App\Exceptions\TransactionValidationException;
use App\Jobs\ProcessTransferJob;
use App\Jobs\ProcessWithdrawalJob;
use App\Jobs\SimulateSandboxTransactionJob;
use App\Models\Agency;
use App\Models\BankBeneficiary;
use App\Models\Country;
use App\Services\Banking\ManualBankTransferProvider;
use App\Models\CountryService;
use App\Models\Operator;
use App\Models\Quote;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Arr;
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
    public function __construct(
        private readonly ExchangeRateService $exchangeRates,
        private readonly TransferRoutingService $routing,
        private readonly TransferAuditService $audit,
        private readonly FeeCalculator $fees,
        private readonly ManualBankTransferProvider $manualBank,
    ) {
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
     * pour empêcher une double dépense), puis routage par TransferRoutingService :
     *  - AUTOMATIC : traitement asynchrone via ProcessTransferJob (Digitwave), statut `processing` ;
     *  - MANUAL    : aucun appel provider, le transfert entre dans la file des agents
     *                (statut `pending_manual_review`). Un rejet/échec rembourse le wallet.
     * Les transferts sandbox ne sont jamais routés vers les agents (simulation).
     *
     * `$idempotencyKey` (durable, en base) : rejouer la même clé renvoie le transfert déjà
     * créé (`replayed` = true) sans débiter une seconde fois.
     *
     * @param  array{country?:string,carrier?:string,currency?:string,operator_id?:int,quote_id?:string,number:string,amount:float|string}  $data
     * @return array{transaction: Transaction, balance: float, fee: float, total: float, replayed: bool}
     *
     * @throws ValidationException si l'opérateur est invalide/hors bornes, le service indisponible ou le solde insuffisant
     */
    public function createTransfer(User $user, array $data, string $channel = 'mobile_app', string $environment = 'production', ?string $idempotencyKey = null): array
    {
        $requestId = 'TX-'.strtoupper(Str::random(12));
        $balanceColumn = Wallet::balanceColumn($environment);
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);

        $result = DB::transaction(function () use ($user, $requestId, $data, $channel, $environment, $balanceColumn, $idempotencyKey) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            // Le verrou du wallet sérialise les requêtes d'un même utilisateur : la
            // vérification de la clé est donc exempte de course.
            if ($existing = $this->findReplay($user, $idempotencyKey, TransferService::MobileMoney, fn (Transaction $t) => $t->recipient_phone === (string) $data['number']
                && abs((float) $t->amount_sent - (float) $data['amount']) < 0.01)) {
                return $this->replayResult($existing, $wallet, $balanceColumn);
            }

            $prepared = $this->prepare($user, $wallet->currency, $data, 'transfer');
            $pricing = $prepared['pricing'];

            $decision = $environment === 'sandbox'
                ? new RoutingDecision(ProcessingMode::Automatic)
                : $this->routing->route($prepared['operator']->country, TransferService::MobileMoney);

            if ($decision->countryService) {
                $this->assertWithinCountryLimits($decision->countryService, $user, $pricing['amount']);
            }

            if ($wallet->{$balanceColumn} < $pricing['total']) {
                throw TransactionValidationException::make('INSUFFICIENT_FUNDS', 'amount', 'Insufficient fund/Balance.');
            }

            $wallet->decrement($balanceColumn, $pricing['total']);

            $transaction = Transaction::create(array_merge($this->transactionAttributes($prepared, $data, 'transfer'), [
                'reference' => $requestId,
                'user_id' => $user->id,
                'channel' => $channel,
                'environment' => $environment,
                'service' => TransferService::MobileMoney,
                'processing_mode' => $decision->mode,
                'provider_id' => $decision->provider?->id,
                'destination_country_id' => $prepared['operator']->country_id,
                'idempotency_key' => $idempotencyKey,
                'status' => $decision->isManual() ? Transaction::STATUS_PENDING_MANUAL_REVIEW : 'processing',
            ]));

            $this->audit->record($transaction, TransferAuditService::CREATED, $user, null, $transaction->status, null, [
                'processing_mode' => $decision->mode->value,
                'provider' => $decision->provider?->code,
            ]);

            return ['transaction' => $transaction, 'balance' => $wallet->{$balanceColumn}, 'fee' => $pricing['fee'], 'total' => $pricing['total'], 'replayed' => false];
        });

        // Dispatché après commit (hors de la transaction DB) pour ne jamais traiter
        // une transaction dont l'écriture n'a pas encore été validée. Un transfert manuel
        // n'est jamais envoyé à un provider : il attend la prise en charge d'un agent.
        if (! $result['replayed']) {
            TransferCreated::dispatch($result['transaction']);
        }

        if (! $result['replayed'] && ! $result['transaction']->isManual()) {
            $this->dispatchProcessing($result['transaction'], ProcessTransferJob::class);
        }

        return $result;
    }

    /**
     * Estimation d'un virement bancaire (frais, total débité, montant reçu) sans rien écrire : mêmes règles
     * que createBankTransfer (routage, limites du pays, frais, taux), solde du wallet compris.
     *
     * @return array{amount: float, fee: float, total: float, currency: string, amount_received: float, currency_received: string, rate: float, balance: float}
     */
    public function estimateBankTransfer(User $user, string $countryCode, float $amount, string $environment = 'production'): array
    {
        $country = Country::resolveActive($countryCode);

        if (! $country) {
            throw TransactionValidationException::make('INVALID_COUNTRY', 'country', 'Pays non supporté ou indisponible.');
        }

        $wallet = Wallet::where('user_id', $user->id)->firstOrFail();
        $price = $this->priceBankTransfer($user, $country, $wallet->currency, $amount, $environment === 'sandbox');
        $balance = (float) $wallet->{Wallet::balanceColumn($environment)};

        if ($balance < $price['total']) {
            throw TransactionValidationException::make('INSUFFICIENT_FUNDS', 'amount', 'Insufficient fund/Balance.');
        }

        return [
            'amount' => $amount,
            'fee' => $price['fee'],
            'total' => $price['total'],
            'currency' => $wallet->currency,
            'amount_received' => $price['converted'],
            'currency_received' => $price['destCurrency'],
            'rate' => $price['rate'],
            'balance' => $balance,
        ];
    }

    /**
     * Routage, limites du pays, taux et frais d'un virement bancaire : source unique partagée par
     * l'estimation et la création, pour que le montant annoncé soit celui réellement débité.
     */
    private function priceBankTransfer(User $user, Country $country, string $walletCurrency, float $amount, bool $sandbox): array
    {
        // Le service doit être ouvert pour le pays même en sandbox ; mais un transfert sandbox
        // n'entre jamais dans la file des agents : il est simulé (SimulateSandboxTransactionJob).
        $decision = $this->routing->route($country, TransferService::BankTransfer);

        if (! $sandbox && ! $decision->isManual()) {
            // Aucun provider bancaire automatique n'est implémenté (config/transfers.php) :
            // le routage ne peut donc pas renvoyer ce cas aujourd'hui.
            throw new \LogicException('Aucun provider bancaire automatique implémenté.');
        }

        if ($decision->countryService) {
            $this->assertWithinCountryLimits($decision->countryService, $user, $amount);
        }

        $destCurrency = strtoupper($country->currency ?: $walletCurrency);
        $rate = $this->exchangeRates->rate($destCurrency, $walletCurrency);
        $converted = $this->exchangeRates->floor($amount / $rate, $destCurrency);
        $fee = $this->fees->compute($country, TransferService::BankTransfer, $decision->provider, $walletCurrency, $amount);
        $total = $amount + $fee;

        return compact('decision', 'destCurrency', 'rate', 'converted', 'fee', 'total');
    }

    /**
     * Initie un virement bancaire vers un pays dont l'admin a activé BANK_TRANSFER.
     * Même garanties que createTransfer (wallet verrouillé, idempotence en base, plafonds,
     * audit). Frais issus de `fee_rules`, conversion au taux manuel de l'admin si la devise
     * du pays diffère de celle du wallet. Aucun provider bancaire automatique n'étant
     * implémenté, le transfert entre dans la file manuelle (`pending_manual_review`).
     *
     * @param  array{country:string,amount:float|string,beneficiary:array<string,mixed>}  $data
     * @return array{transaction: Transaction, balance: float, fee: float, total: float, replayed: bool}
     *
     * @throws ValidationException
     */
    public function createBankTransfer(User $user, array $data, ?string $idempotencyKey = null, string $channel = 'mobile_app', string $environment = 'production'): array
    {
        $country = Country::resolveActive((string) $data['country']);

        if (! $country) {
            throw TransactionValidationException::make('INVALID_COUNTRY', 'country', 'Pays non supporté ou indisponible.');
        }

        $requestId = 'BT-'.strtoupper(Str::random(12));

        $balanceColumn = Wallet::balanceColumn($environment);
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);

        $result = DB::transaction(function () use ($user, $country, $data, $requestId, $idempotencyKey, $channel, $environment, $balanceColumn) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $amount = (float) $data['amount'];

            if ($existing = $this->findReplay($user, $idempotencyKey, TransferService::BankTransfer, fn (Transaction $t) => $t->destination_country_id === $country->id
                && abs((float) $t->amount_sent - $amount) < 0.01)) {
                return $this->replayResult($existing, $wallet, $balanceColumn);
            }

            $sandbox = $environment === 'sandbox';
            $walletCurrency = $wallet->currency;

            ['decision' => $decision, 'destCurrency' => $destCurrency, 'rate' => $rate, 'converted' => $converted, 'fee' => $fee, 'total' => $total]
                = $this->priceBankTransfer($user, $country, $walletCurrency, $amount, $sandbox);

            if ((float) $wallet->{$balanceColumn} < $total) {
                throw TransactionValidationException::make('INSUFFICIENT_FUNDS', 'amount', 'Insufficient fund/Balance.');
            }

            $wallet->decrement($balanceColumn, $total);

            $beneficiary = BankBeneficiary::create(array_merge(
                Arr::only($data['beneficiary'], BankBeneficiary::FIELDS),
                ['user_id' => $user->id, 'country_id' => $country->id]
            ));

            $status = $sandbox
                ? 'processing'
                : $this->manualBank->transfer([
                    'reference' => $requestId,
                    'amount' => $converted,
                    'currency' => $destCurrency,
                    'country' => $country->iso,
                    'beneficiary' => $beneficiary->toArray(),
                ])->status;

            $transaction = Transaction::create([
                'reference' => $requestId,
                'type' => 'transfer',
                'service' => TransferService::BankTransfer,
                'processing_mode' => $sandbox ? ProcessingMode::Automatic : $decision->mode,
                'provider_id' => null,
                'channel' => $channel,
                'environment' => $environment,
                'user_id' => $user->id,
                'bank_beneficiary_id' => $beneficiary->id,
                'recipient_name' => $beneficiary->full_name,
                'recipient_phone' => (string) $beneficiary->phone,
                'recipient_operator' => 'BANK',
                'destination_country_id' => $country->id,
                'country_name' => $country->name,
                'amount_sent' => $amount,
                'currency_sent' => $walletCurrency,
                'fees' => $fee,
                'exchange_rate' => $rate,
                'amount_to_receive' => $converted,
                'currency_received' => $destCurrency,
                'idempotency_key' => $idempotencyKey,
                'status' => $status,
            ]);

            $this->audit->record($transaction, TransferAuditService::CREATED, $user, null, $transaction->status, null, [
                'processing_mode' => $transaction->processing_mode->value,
                'service' => TransferService::BankTransfer->value,
            ]);

            return ['transaction' => $transaction, 'balance' => (float) $wallet->{$balanceColumn}, 'fee' => $fee, 'total' => $total, 'replayed' => false];
        });

        if (! $result['replayed']) {
            TransferCreated::dispatch($result['transaction']);

            if ($environment === 'sandbox') {
                SimulateSandboxTransactionJob::dispatch($result['transaction']);
            }
        }

        return $result;
    }

    /**
     * Une clé vide équivaut à « pas de clé » ; une clé trop longue pour la colonne est refusée
     * proprement (sinon l'insertion échouerait en erreur 500 après le débit annulé).
     */
    private function normalizeIdempotencyKey(?string $key): ?string
    {
        $key = trim((string) $key);

        if ($key === '') {
            return null;
        }

        if (mb_strlen($key) > 100) {
            throw TransactionValidationException::make('INVALID_IDEMPOTENCY_KEY', 'idempotency_key', "La clé d'idempotence ne doit pas dépasser 100 caractères.");
        }

        return $key;
    }

    /**
     * Transfert déjà créé avec cette clé pour cet utilisateur, ou null. La clé est unique par
     * utilisateur tous services confondus : la réutiliser pour un autre service, ou avec une
     * requête différente (`$matches` faux), est refusée au lieu de rejouer un mauvais transfert.
     *
     * @param  callable(Transaction): bool  $matches
     */
    private function findReplay(User $user, ?string $key, TransferService $service, callable $matches): ?Transaction
    {
        if ($key === null) {
            return null;
        }

        $existing = Transaction::where('user_id', $user->id)->where('idempotency_key', $key)->first();

        if (! $existing) {
            return null;
        }

        if ($existing->service !== $service || ! $matches($existing)) {
            throw TransactionValidationException::make('IDEMPOTENCY_KEY_REUSED', 'idempotency_key', "Cette clé d'idempotence a déjà été utilisée avec une requête différente.");
        }

        return $existing;
    }

    /** @return array{transaction: Transaction, balance: float, fee: float, total: float, replayed: bool} */
    private function replayResult(Transaction $existing, Wallet $wallet, string $balanceColumn): array
    {
        return [
            'transaction' => $existing,
            'balance' => (float) $wallet->{$balanceColumn},
            'fee' => (float) $existing->fees,
            'total' => (float) $existing->amount_sent + (float) $existing->fees,
            'replayed' => true,
        ];
    }

    /**
     * Retrait et dépôt n'ont pas de traitement manuel : ils ne sont possibles que si le pays
     * est routé en automatique. Un pays désactivé, un service en MANUAL ou Digitwave coupé par
     * l'admin les refuse (comme TransferRoutingService le fait pour les transferts).
     */
    private function assertAutomaticRoute(Operator $operator, string $environment): void
    {
        if ($environment === 'sandbox') {
            return;
        }

        if ($this->routing->route($operator->country, TransferService::MobileMoney)->isManual()) {
            throw TransactionValidationException::make('SERVICE_UNAVAILABLE', 'country', "Ce service est momentanément indisponible pour « {$operator->country->name} ».");
        }
    }

    /**
     * Applique les bornes et plafonds (journalier / mensuel) configurés par l'admin pour
     * le service du pays, dans la devise du wallet. Les transferts échoués, rejetés ou
     * annulés ne consomment pas le plafond.
     */
    private function assertWithinCountryLimits(CountryService $config, User $user, float $amount): void
    {
        if (($config->min_amount !== null && $amount < (float) $config->min_amount)
            || ($config->max_amount !== null && $amount > (float) $config->max_amount)) {
            throw TransactionValidationException::make('AMOUNT_OUT_OF_BOUNDS', 'amount', 'Montant hors des limites autorisées pour ce pays.');
        }

        $used = fn ($since) => (float) Transaction::where('user_id', $user->id)
            ->where('destination_country_id', $config->country_id)
            ->where('service', $config->service->value)
            ->where('environment', 'production')
            ->whereNotIn('status', ['failed', 'reversed', Transaction::STATUS_REJECTED, Transaction::STATUS_CANCELLED])
            ->where('created_at', '>=', $since)
            ->sum('amount_sent');

        if ($config->daily_limit !== null && $used(now()->startOfDay()) + $amount > (float) $config->daily_limit) {
            throw TransactionValidationException::make('DAILY_LIMIT_EXCEEDED', 'amount', 'Plafond journalier dépassé pour ce pays.');
        }

        if ($config->monthly_limit !== null && $used(now()->startOfMonth()) + $amount > (float) $config->monthly_limit) {
            throw TransactionValidationException::make('MONTHLY_LIMIT_EXCEEDED', 'amount', 'Plafond mensuel dépassé pour ce pays.');
        }
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
    public function createWithdrawal(User $user, Agency $agency, array $data, string $channel = 'mobile_app', string $environment = 'production'): array
    {
        $requestId = 'WD-'.strtoupper(Str::random(12));
        $balanceColumn = Wallet::balanceColumn($environment);

        $result = DB::transaction(function () use ($user, $agency, $requestId, $data, $channel, $environment, $balanceColumn) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $prepared = $this->prepare($user, $wallet->currency, $data, 'withdrawal');
            $pricing = $prepared['pricing'];
            $this->assertAutomaticRoute($prepared['operator'], $environment);

            if ($wallet->{$balanceColumn} < $pricing['total']) {
                throw TransactionValidationException::make('INSUFFICIENT_FUNDS', 'amount', 'Solde insuffisant pour effectuer ce retrait.');
            }

            $wallet->decrement($balanceColumn, $pricing['total']);

            $transaction = Transaction::create(array_merge($this->transactionAttributes($prepared, $data, 'withdrawal'), [
                'reference' => $requestId,
                'user_id' => $user->id,
                'channel' => $channel,
                'environment' => $environment,
                'agency_id' => $agency->id,
                'status' => 'pending',
            ]));

            return ['transaction' => $transaction, 'balance' => $wallet->{$balanceColumn}, 'fee' => $pricing['fee'], 'total' => $pricing['total']];
        });

        $this->dispatchProcessing($result['transaction'], ProcessTransferJob::class);

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
    public function createDeposit(User $user, array $data, string $channel = 'mobile_app', string $environment = 'production'): array
    {
        $wallet = $user->wallet;
        $requestId = 'DP-'.strtoupper(Str::random(12));

        // Une erreur (opérateur, cotation) annule tout : rien n'est écrit.
        [$transaction, $pricing] = DB::transaction(function () use ($user, $wallet, $requestId, $data, $channel, $environment) {
            $prepared = $this->prepare($user, $wallet->currency, $data, 'deposit');
            $this->assertAutomaticRoute($prepared['operator'], $environment);

            $transaction = Transaction::create(array_merge($this->transactionAttributes($prepared, $data, 'deposit'), [
                'reference' => $requestId,
                'user_id' => $user->id,
                'channel' => $channel,
                'environment' => $environment,
                'status' => 'pending',
            ]));

            return [$transaction, $prepared['pricing']];
        });

        $this->dispatchProcessing($transaction, ProcessWithdrawalJob::class);

        return ['transaction' => $transaction, 'balance' => (float) $wallet->{Wallet::balanceColumn($environment)}, 'fee' => $pricing['fee'], 'total' => $pricing['total']];
    }

    /**
     * Traitement asynchrone : le job Digitwave réel en production, la simulation en
     * sandbox (aucun appel au fournisseur de paiement).
     *
     * @param  class-string  $productionJob
     */
    private function dispatchProcessing(Transaction $transaction, string $productionJob): void
    {
        if ($transaction->environment === 'sandbox') {
            SimulateSandboxTransactionJob::dispatch($transaction);

            return;
        }

        $productionJob::dispatch($transaction);
    }
}
