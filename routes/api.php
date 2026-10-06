<?php

use App\Http\Controllers\Api\Admin\AgentController;
use App\Http\Controllers\Api\Admin\BankFieldRuleController;
use App\Http\Controllers\Api\Admin\CountryServiceController;
use App\Http\Controllers\Api\Admin\FeeRuleController;
use App\Http\Controllers\Api\Admin\ProviderController;
use App\Http\Controllers\Api\Admin\TransferController as AdminTransferController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\CurrencyController;
use App\Http\Controllers\Api\Admin\ExchangeRateController;
use App\Http\Controllers\Api\Admin\MerchantController;
use App\Http\Controllers\Api\Admin\OperatorController;
use App\Http\Controllers\Api\Admin\KycController as AdminKycController;
use App\Http\Controllers\Api\Admin\MerchantKybController;
use App\Http\Controllers\Api\Admin\MonitoringController;
use App\Http\Controllers\Api\Admin\ReconciliationController;
use App\Http\Controllers\Api\Admin\TransactionController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\Admin\WalletController;
use App\Http\Controllers\Api\Agent\AuthController as AgentAuthController;
use App\Http\Controllers\Api\Agent\TransferController as AgentTransferController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankTransferController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\Merchant\ApiKeyController;
use App\Http\Controllers\Api\Merchant\KybController as MerchantKybPortalController;
use App\Http\Controllers\Api\Merchant\WebhookController as MerchantWebhookController;
use App\Http\Controllers\Api\Merchant\BankTransferController as MerchantBankTransferController;
use App\Http\Controllers\Api\Merchant\AuthController as MerchantAuthController;
use App\Http\Controllers\Api\Merchant\CountryController as MerchantCountryController;
use App\Http\Controllers\Api\Merchant\PortalController as MerchantPortalController;
use App\Http\Controllers\Api\Merchant\TransferController as MerchantTransferController;
use App\Http\Controllers\Api\Merchant\WalletController as MerchantWalletController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PublicCoverageController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\SecurityController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserTransferController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| L'ensemble des routes est défini une seule fois dans la closure ci-dessous,
| puis enregistré à deux emplacements : sans préfixe de version (compatibilité
| avec l'app Flutter et le dashboard existants, qui appellent /api/*) et sous
| /api/v1/* (alias pour les futurs clients). Les deux exposent exactement les
| mêmes routes — il n'y a qu'une seule définition à maintenir.
|
*/

$registerApiRoutes = function () {
    // ==========================================
    // 1. ROUTES D'AUTHENTIFICATION UTILISATEUR (App mobile - clients)
    // ==========================================
    // Couverture pays publique (page d'accueil) : mise en cache, sans donnée sensible.
    Route::middleware('throttle:60,1')->get('/public/coverage', [PublicCoverageController::class, 'index']);

    Route::middleware('throttle:auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
    });
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/profile', [AuthController::class, 'profile']);
        Route::post('/legal/accept', [AuthController::class, 'acceptLegal']);

        Route::post('/profile/update', [UserController::class, 'updateProfile']);
        Route::post('/profile/update-pin', [UserController::class, 'updateCodePin']);
        Route::post('/profile/change-password', [UserController::class, 'changePassword']);

    });

    // ==========================================
    // 2. ROUTES TRANSACTIONNELLES (Flutter App)
    // ==========================================
    Route::middleware(['auth:sanctum', 'active'])->group(function () {

        // Pays et opérateurs
        Route::get('/countries', [CountryController::class, 'index']);
        Route::get('/countries/{iso}', [CountryController::class, 'show']);

        // Cotation (conversion XAF → devise de l'opérateur) avant validation d'une opération
        Route::post('/quote', [QuoteController::class, 'store']);

        // Transactions (Payout & Cash-In)
        // 'pin.verify' vérifie le code PIN de transaction ; 'idempotent:<scope>' bloque les doublons
        // (double-tap, retry réseau) pendant quelques secondes, par utilisateur et par type d'opération.
        Route::post('/transfer', [TransferController::class, 'initiateTransfer'])
            ->middleware(['pin.verify', 'idempotent:transfer']);
        // Virement bancaire (pays dont BANK_TRANSFER est activé par l'admin) : traitement manuel par les agents.
        Route::get('/bank-transfer/countries', [BankTransferController::class, 'countries']);
        Route::get('/bank-transfer/requirements', [BankTransferController::class, 'requirements']);
        Route::post('/bank-transfer/estimate', [BankTransferController::class, 'estimate'])->middleware('throttle:60,1');
        Route::post('/bank-transfer', [BankTransferController::class, 'store'])
            ->middleware(['pin.verify', 'idempotent:bank_transfer']);
        Route::post('/withdrawal', [TransferController::class, 'initiateWithdrawal'])
            ->middleware(['pin.verify', 'idempotent:withdrawal']);
        Route::post('/deposit', [TransferController::class, 'initiateDeposit'])
            ->middleware(['pin.verify', 'idempotent:deposit']);

        // Mes transferts (suivi, annulation d'un transfert manuel pas encore pris en charge)
        Route::get('/transfers', [UserTransferController::class, 'index']);
        Route::get('/transfers/{transfer}', [UserTransferController::class, 'show'])->whereNumber('transfer');
        Route::post('/transfers/{transfer}/cancel', [UserTransferController::class, 'cancel'])->whereNumber('transfer');

        // KYC : niveau, plafonds et dépôt des pièces justificatives
        Route::get('/kyc', [KycController::class, 'show']);
        Route::post('/kyc/submissions', [KycController::class, 'submit'])->middleware('throttle:5,60');

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);

        // Vérification de statut et historique
        Route::get('/get_request', [TransferController::class, 'checkStatus']);
        Route::get('/transactions', [TransferController::class, 'recentTransactions']); // Ajouté pour correspondre à ton ApiClient
        Route::get('/history', [TransferController::class, 'historyList']);            // Ajouté pour correspondre à ton ApiClient
        Route::get('/stats', [TransferController::class, 'stats']);
        Route::get('/transactions/{id}/status', [TransferController::class, 'getTransactionStatus']);
    });

    // Agents : file de traitement manuel (rôle 'agent' uniquement, aucune route de configuration).
    Route::middleware('throttle:auth')->post('/agent/auth/login', [AgentAuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'active', 'agent.role'])->prefix('agent')->group(function () {
        Route::post('/auth/logout', [AgentAuthController::class, 'logout']);
        Route::get('/transfers', [AgentTransferController::class, 'index']);
        Route::get('/transfers/{transfer}', [AgentTransferController::class, 'show'])->whereNumber('transfer');
        Route::post('/transfers/{transfer}/claim', [AgentTransferController::class, 'claim'])->whereNumber('transfer');
        Route::post('/transfers/{transfer}/start', [AgentTransferController::class, 'start'])->whereNumber('transfer');
        Route::post('/transfers/{transfer}/release', [AgentTransferController::class, 'release'])->whereNumber('transfer');
        Route::post('/transfers/{transfer}/complete', [AgentTransferController::class, 'complete'])->whereNumber('transfer');
        Route::post('/transfers/{transfer}/reject', [AgentTransferController::class, 'reject'])->whereNumber('transfer');
        Route::post('/transfers/{transfer}/fail', [AgentTransferController::class, 'fail'])->whereNumber('transfer');
        Route::post('/transfers/{transfer}/proof', [AgentTransferController::class, 'proof'])->whereNumber('transfer');
    });

    /*
    |--------------------------------------------------------------------------
    | Routes Privées - Console d'Administration (Sécurisées par Sanctum)
    |--------------------------------------------------------------------------
    */

    // Connexion admin : forcément publique (pas encore de token à ce stade), mais regroupée
    // sous /admin/auth/* par cohérence avec le reste des routes d'administration.
    Route::middleware('throttle:auth')->post('/admin/auth/login', [SecurityController::class, 'login']);
    Route::middleware('throttle:auth')->post('/admin/auth/2fa', [SecurityController::class, 'twoFactor']);

    Route::middleware(['auth:sanctum', 'active', 'admin.role'])->prefix('admin')->group(function () {

        // Déconnexion de la session admin
        Route::post('/auth/logout', [SecurityController::class, 'logout']);

        // 2FA du compte admin
        Route::get('/2fa', [TwoFactorController::class, 'status']);
        Route::post('/2fa/setup', [TwoFactorController::class, 'setup'])->middleware('throttle:10,1');
        Route::post('/2fa/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:10,1');
        Route::post('/2fa/disable', [TwoFactorController::class, 'disable'])->middleware('throttle:10,1');
        Route::post('/2fa/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->middleware('throttle:10,1');

        // Gestion des Opérateurs (Kill switch, modification des frais fixes & % )
        Route::get('/operators', [OperatorController::class, 'index']);
        Route::post('/operators', [OperatorController::class, 'store']);
        Route::put('/operators/{id}', [OperatorController::class, 'update']);
        Route::delete('/operators/{id}', [OperatorController::class, 'destroy']);

        // Taux de change manuels (ajout seul : chaque modification crée une nouvelle ligne)
        Route::get('/exchange-rates', [ExchangeRateController::class, 'index']);
        Route::post('/exchange-rates', [ExchangeRateController::class, 'store']);

        // Devises (nom + symbole)
        Route::get('/currencies', [CurrencyController::class, 'index']);
        Route::post('/currencies', [CurrencyController::class, 'store']);
        Route::put('/currencies/{id}', [CurrencyController::class, 'update']);
        Route::delete('/currencies/{id}', [CurrencyController::class, 'destroy']);

        // Gestion des Pays / Corridors régionaux
        Route::get('/countries', [App\Http\Controllers\Api\Admin\CountryController::class, 'index']);
        Route::post('/countries', [App\Http\Controllers\Api\Admin\CountryController::class, 'store']);
        Route::put('/countries/{id}', [App\Http\Controllers\Api\Admin\CountryController::class, 'update']);
        Route::delete('/countries/{id}', [App\Http\Controllers\Api\Admin\CountryController::class, 'destroy']);

        // Providers, services par pays (Mobile Money / virement bancaire), frais et champs bancaires
        Route::get('/providers', [ProviderController::class, 'index']);
        Route::post('/providers', [ProviderController::class, 'store']);
        Route::put('/providers/{id}', [ProviderController::class, 'update']);
        Route::delete('/providers/{id}', [ProviderController::class, 'destroy']);

        Route::get('/country-services', [CountryServiceController::class, 'index']);
        Route::post('/country-services', [CountryServiceController::class, 'store']);
        Route::put('/country-services/{id}', [CountryServiceController::class, 'update']);
        Route::delete('/country-services/{id}', [CountryServiceController::class, 'destroy']);

        Route::get('/fee-rules', [FeeRuleController::class, 'index']);
        Route::post('/fee-rules', [FeeRuleController::class, 'store']);
        Route::put('/fee-rules/{id}', [FeeRuleController::class, 'update']);
        Route::delete('/fee-rules/{id}', [FeeRuleController::class, 'destroy']);

        Route::get('/countries/{id}/bank-fields', [BankFieldRuleController::class, 'show']);
        Route::put('/countries/{id}/bank-fields', [BankFieldRuleController::class, 'update']);

        // Agents (traitement manuel) et supervision des transferts
        Route::get('/agents', [AgentController::class, 'index']);
        Route::post('/agents', [AgentController::class, 'store']);
        Route::put('/agents/{id}', [AgentController::class, 'update']);

        Route::get('/transfers', [AdminTransferController::class, 'index']);
        Route::get('/transfers/{id}', [AdminTransferController::class, 'show'])->whereNumber('id');
        Route::get('/manual-transfers', [AdminTransferController::class, 'manual']);
        Route::post('/transfers/{id}/release', [AdminTransferController::class, 'release'])->whereNumber('id');

        // Gestion & Audit de la Masse Monétaire (Wallets)
        Route::get('/wallets', [WalletController::class, 'index']);
        // Mutation d'ajustement manuel : crée ou détruit de la monnaie, réservé au superadmin
        // Double validation : un admin demande, un AUTRE superadmin approuve (voir WalletAdjustmentService).
        Route::post('/wallets/{id}/adjust', [WalletController::class, 'adjust'])->middleware('two_factor');
        Route::get('/wallet-adjustments', [WalletController::class, 'pendingAdjustments']);
        Route::post('/wallet-adjustments/{id}/approve', [WalletController::class, 'approveAdjustment'])
            ->whereNumber('id')->middleware(['admin.role:superadmin', 'two_factor']);
        Route::post('/wallet-adjustments/{id}/reject', [WalletController::class, 'rejectAdjustment'])
            ->whereNumber('id')->middleware(['admin.role:superadmin', 'two_factor']);
        Route::get('/wallets/{id}/adjustments', [WalletController::class, 'adjustments']); // Historique des ajustements

        // Journal d'Audit Global (Transactions de la passerelle)
        Route::get('/kyc/submissions', [AdminKycController::class, 'index']);
        Route::get('/kyc/submissions/{id}', [AdminKycController::class, 'show'])->whereNumber('id');
        Route::get('/kyc/submissions/{id}/files/{index}', [AdminKycController::class, 'file'])->whereNumber(['id', 'index']);
        Route::post('/kyc/submissions/{id}/approve', [AdminKycController::class, 'approve'])->whereNumber('id');
        Route::post('/kyc/submissions/{id}/reject', [AdminKycController::class, 'reject'])->whereNumber('id');
        Route::get('/kyc/limits', [AdminKycController::class, 'limits']);
        Route::put('/kyc/limits', [AdminKycController::class, 'updateLimits'])->middleware(['admin.role:superadmin', 'two_factor']);

        Route::get('/monitoring', [MonitoringController::class, 'index']);

        Route::get('/reconciliation', [ReconciliationController::class, 'index']);
        Route::post('/reconciliation/{id}/resolve', [ReconciliationController::class, 'resolve'])
            ->whereNumber('id')->middleware(['admin.role:superadmin', 'two_factor']);

        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::get('/transactions/export/excel', [TransactionController::class, 'exportExcel']);
        Route::get('/transactions/export/pdf', [TransactionController::class, 'exportPdf']);
        Route::get('/dashboard/stats', [DashboardController::class, 'getStats']);

        // Gestion des Marchands B2B (intégrateurs de la passerelle)
        Route::get('/merchants', [MerchantController::class, 'index']);
        Route::put('/merchants/{id}', [MerchantController::class, 'update'])->middleware('two_factor');
        Route::get('/merchants/{id}/transactions', [MerchantController::class, 'transactions'])->whereNumber('id');
        Route::get('/merchants/{id}/transactions/export', [MerchantController::class, 'exportTransactions'])->whereNumber('id')->middleware('throttle:10,1');

        // Dossier de vérification (KYB) des marchands
        Route::get('/merchants/{id}/kyb', [MerchantKybController::class, 'show'])->whereNumber('id');
        Route::get('/merchants/{id}/kyb/documents/{documentId}/file', [MerchantKybController::class, 'file'])->whereNumber(['id', 'documentId'])->middleware('throttle:60,1');
        // Saisie pour le compte du marchand (documents reçus hors plateforme) : action sensible → 2FA, justification obligatoire.
        Route::post('/merchants/{id}/kyb/documents', [MerchantKybController::class, 'uploadDocument'])->whereNumber('id')->middleware(['two_factor', 'throttle:30,60']);
        Route::put('/merchants/{id}/kyb/profile', [MerchantKybController::class, 'saveProfile'])->whereNumber('id')->middleware('two_factor');
        Route::post('/merchants/{id}/kyb/documents/{documentId}/approve', [MerchantKybController::class, 'approveDocument'])->whereNumber(['id', 'documentId']);
        Route::post('/merchants/{id}/kyb/documents/{documentId}/reject', [MerchantKybController::class, 'rejectDocument'])->whereNumber(['id', 'documentId']);
        // Décision finale : superadmin + 2FA (elle débloque le passage en production).
        Route::post('/merchants/{id}/kyb/approve', [MerchantKybController::class, 'approve'])->whereNumber('id')->middleware(['admin.role:superadmin', 'two_factor']);
        Route::post('/merchants/{id}/kyb/reject', [MerchantKybController::class, 'reject'])->whereNumber('id')->middleware(['admin.role:superadmin', 'two_factor']);

        // Gestion des Utilisateurs (clients mobile money de l'app Flutter)
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::put('/users/{id}', [AdminUserController::class, 'update']);
        // Réinitialisation manuelle : demande reçue par l'admin via WhatsApp
        Route::post('/users/{id}/generate-password', [AdminUserController::class, 'generatePassword'])->middleware('two_factor');
    });
};

// Routes historiques, sans préfixe de version.
$registerApiRoutes();

// Alias versionné /api/v1/* — strictement les mêmes routes.
Route::prefix('v1')->group($registerApiRoutes);

/*
|--------------------------------------------------------------------------
| Espace Self-Service Marchand (SaaS) — inscription, connexion, clés API
|--------------------------------------------------------------------------
| Distinct des routes 1/2/3 ci-dessus : un marchand crée ici son propre
| compte (role='merchant') pour accéder à son dashboard et générer les
| clés API qu'il utilisera pour appeler /api/v1/gateway/* depuis ses
| propres serveurs.
*/
Route::prefix('merchants')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/register', [MerchantAuthController::class, 'register']);
        Route::post('/login', [MerchantAuthController::class, 'login']);
        Route::post('/2fa', [MerchantAuthController::class, 'twoFactor']);
    });

    Route::middleware(['auth:sanctum', 'active', 'merchant.role'])->group(function () {
        Route::post('/logout', [MerchantAuthController::class, 'logout']);
        Route::get('/profile', [MerchantAuthController::class, 'profile']);

        // 2FA du compte marchand
        Route::get('/2fa', [TwoFactorController::class, 'status']);
        Route::post('/2fa/setup', [TwoFactorController::class, 'setup'])->middleware('throttle:10,1');
        Route::post('/2fa/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:10,1');
        Route::post('/2fa/disable', [TwoFactorController::class, 'disable'])->middleware('throttle:10,1');
        Route::post('/2fa/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->middleware('throttle:10,1');

        Route::get('/api-keys', [ApiKeyController::class, 'index']);
        Route::post('/api-keys', [ApiKeyController::class, 'store']);
        Route::delete('/api-keys/{id}', [ApiKeyController::class, 'destroy']);

        // Dossier de vérification de l'entreprise (KYB), requis pour passer en production
        Route::get('/kyb', [MerchantKybPortalController::class, 'show']);
        Route::put('/kyb/profile', [MerchantKybPortalController::class, 'saveProfile']);
        Route::post('/kyb/documents', [MerchantKybPortalController::class, 'upload'])->middleware('throttle:30,60');
        Route::get('/kyb/documents/{id}/file', [MerchantKybPortalController::class, 'file'])->whereNumber('id');
        Route::post('/kyb/submit', [MerchantKybPortalController::class, 'submit'])->middleware('throttle:10,60');

        // Webhooks sortants (notifications de changement de statut)
        Route::get('/webhooks', [MerchantWebhookController::class, 'index']);
        Route::post('/webhooks', [MerchantWebhookController::class, 'store']);
        Route::put('/webhooks/{id}', [MerchantWebhookController::class, 'update']);
        Route::delete('/webhooks/{id}', [MerchantWebhookController::class, 'destroy']);
        Route::post('/webhooks/{id}/test', [MerchantWebhookController::class, 'test'])->middleware('throttle:10,1');
        Route::get('/webhooks/{id}/deliveries', [MerchantWebhookController::class, 'deliveries']);
        Route::post('/webhook-deliveries/{id}/redeliver', [MerchantWebhookController::class, 'redeliver'])->middleware('throttle:30,1');

        // Wallet et transactions du portail (live et sandbox)
        Route::get('/wallet', [MerchantPortalController::class, 'wallet']);
        Route::get('/transactions', [MerchantPortalController::class, 'transactions']);
        Route::post('/sandbox/top-up', [MerchantPortalController::class, 'topUpSandbox']);
    });
});

/*
|--------------------------------------------------------------------------
| API Gateway B2B (authentification par clé API — usage serveur-à-serveur)
|--------------------------------------------------------------------------
| Contrôleurs Api\Merchant\* dédiés — indépendants de Api\TransferController /
| Api\CountryController (app mobile). Seule la logique de débit du wallet est
| partagée (App\Services\TransactionService), la façade HTTP (validation,
| format de réponse, idempotence) est propre à ce canal :
| - pas de 'pin.verify' (la clé API est déjà le secret serveur-à-serveur) ;
| - idempotence par en-tête 'Idempotency-Key' (middleware 'idempotency.key')
|   plutôt que par fenêtre de 15s, sur les 3 routes POST.
*/
Route::prefix('v1/gateway')->group(function () {
    Route::get('/countries', [MerchantCountryController::class, 'index'])
        ->middleware('auth.apikey:countries.read');
    Route::get('/countries/{iso}', [MerchantCountryController::class, 'show'])
        ->middleware('auth.apikey:countries.read');

    // Scope vérifié dans le contrôleur selon le type coté ({type}.write).
    Route::post('/quotes', [QuoteController::class, 'store'])
        ->middleware('auth.apikey');

    Route::post('/transfers', [MerchantTransferController::class, 'initiateTransfer'])
        ->middleware(['auth.apikey:transfer.write', 'idempotency.key']);
    // Virement bancaire : pays/champs (countries.read) puis création (bank_transfer.write, Idempotency-Key).
    // Le suivi se fait via GET /transactions/{reference}.
    Route::get('/bank-countries', [MerchantBankTransferController::class, 'countries'])
        ->middleware('auth.apikey:countries.read');
    Route::post('/bank-transfers', [MerchantBankTransferController::class, 'store'])
        ->middleware(['auth.apikey:bank_transfer.write', 'idempotency.key']);
    Route::post('/withdrawals', [MerchantTransferController::class, 'initiateWithdrawal'])
        ->middleware(['auth.apikey:withdrawal.write', 'idempotency.key']);
    Route::post('/deposits', [MerchantTransferController::class, 'initiateDeposit'])
        ->middleware(['auth.apikey:deposit.write', 'idempotency.key']);

    Route::get('/wallet', [MerchantWalletController::class, 'show'])
        ->middleware('auth.apikey:wallet.read');

    Route::get('/transactions', [MerchantTransferController::class, 'index'])
        ->middleware('auth.apikey:transactions.read');
    Route::get('/transactions/{reference}', [MerchantTransferController::class, 'show'])
        ->middleware('auth.apikey:transactions.read');
});

/*
|--------------------------------------------------------------------------
| Webhooks entrants (fournisseurs de paiement)
|--------------------------------------------------------------------------
| Route publique côté Sanctum (Digitwave n'a pas de token utilisateur) mais
| authentifiée par signature HMAC (voir VerifyDigitwaveSignature). URL à
| déclarer une seule fois dans le dashboard Digitwave — pas besoin d'alias /v1.
*/
Route::post('/webhooks/digitwave', [WebhookController::class, 'digitwave'])
    ->middleware('verify.digitwave.signature');
