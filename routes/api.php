<?php

use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\MerchantController;
use App\Http\Controllers\Api\Admin\OperatorController;
use App\Http\Controllers\Api\Admin\TransactionController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\Admin\WalletController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\Merchant\ApiKeyController;
use App\Http\Controllers\Api\Merchant\AuthController as MerchantAuthController;
use App\Http\Controllers\Api\Merchant\CountryController as MerchantCountryController;
use App\Http\Controllers\Api\Merchant\TransferController as MerchantTransferController;
use App\Http\Controllers\Api\SecurityController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\UserController;
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
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
    });
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/profile', [AuthController::class, 'profile']);

        Route::post('/profile/update', [UserController::class, 'updateProfile']);
        Route::post('/profile/update-pin', [UserController::class, 'updateCodePin']);
        Route::post('/profile/change-password', [UserController::class, 'changePassword']);

    });

    // ==========================================
    // 2. ROUTES TRANSACTIONNELLES (Flutter App)
    // ==========================================
    Route::middleware('auth:sanctum')->group(function () {

        // Pays et opérateurs
        Route::get('/countries', [CountryController::class, 'index']);
        Route::get('/countries/{iso}', [CountryController::class, 'show']);

        // Transactions (Payout & Cash-In)
        // 'pin.verify' vérifie le code PIN de transaction ; 'idempotent:<scope>' bloque les doublons
        // (double-tap, retry réseau) pendant quelques secondes, par utilisateur et par type d'opération.
        Route::post('/transfer', [TransferController::class, 'initiateTransfer'])
            ->middleware(['pin.verify', 'idempotent:transfer']);
        Route::post('/withdrawal', [TransferController::class, 'initiateWithdrawal'])
            ->middleware(['pin.verify', 'idempotent:withdrawal']);
        Route::post('/deposit', [TransferController::class, 'initiateDeposit'])
            ->middleware(['pin.verify', 'idempotent:deposit']);

        // Vérification de statut et historique
        Route::get('/get_request', [TransferController::class, 'checkStatus']);
        Route::get('/transactions', [TransferController::class, 'recentTransactions']); // Ajouté pour correspondre à ton ApiClient
        Route::get('/history', [TransferController::class, 'historyList']);            // Ajouté pour correspondre à ton ApiClient
        Route::get('/transactions/{id}/status', [TransferController::class, 'getTransactionStatus']);
    });

    /*
    |--------------------------------------------------------------------------
    | Routes Privées - Console d'Administration (Sécurisées par Sanctum)
    |--------------------------------------------------------------------------
    */

    // Connexion admin : forcément publique (pas encore de token à ce stade), mais regroupée
    // sous /admin/auth/* par cohérence avec le reste des routes d'administration.
    Route::middleware('throttle:auth')->post('/admin/auth/login', [SecurityController::class, 'login']);

    Route::middleware(['auth:sanctum', 'admin.role'])->prefix('admin')->group(function () {

        // Déconnexion de la session admin
        Route::post('/auth/logout', [SecurityController::class, 'logout']);

        // Gestion des Opérateurs (Kill switch, modification des frais fixes & % )
        Route::get('/operators', [OperatorController::class, 'index']);
        Route::post('/operators', [OperatorController::class, 'store']);
        Route::put('/operators/{id}', [OperatorController::class, 'update']);

        // Gestion des Pays / Corridors régionaux
        Route::get('/countries', [App\Http\Controllers\Api\Admin\CountryController::class, 'index']);
        Route::post('/countries', [App\Http\Controllers\Api\Admin\CountryController::class, 'store']);
        Route::put('/countries/{id}', [App\Http\Controllers\Api\Admin\CountryController::class, 'update']);

        // Gestion & Audit de la Masse Monétaire (Wallets)
        Route::get('/wallets', [WalletController::class, 'index']);
        Route::post('/wallets/{id}/adjust', [WalletController::class, 'adjust']); // Mutation d'ajustement manuel
        Route::get('/wallets/{id}/adjustments', [WalletController::class, 'adjustments']); // Historique des ajustements

        // Journal d'Audit Global (Transactions de la passerelle)
        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::get('/transactions/export/excel', [TransactionController::class, 'exportExcel']);
        Route::get('/transactions/export/pdf', [TransactionController::class, 'exportPdf']);
        Route::get('/dashboard/stats', [DashboardController::class, 'getStats']);

        // Gestion des Marchands B2B (intégrateurs de la passerelle)
        Route::get('/merchants', [MerchantController::class, 'index']);
        Route::put('/merchants/{id}', [MerchantController::class, 'update']);

        // Gestion des Utilisateurs (clients mobile money de l'app Flutter)
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::put('/users/{id}', [AdminUserController::class, 'update']);
        // Réinitialisation manuelle : demande reçue par l'admin via WhatsApp
        Route::post('/users/{id}/generate-password', [AdminUserController::class, 'generatePassword']);
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
    });

    Route::middleware(['auth:sanctum', 'merchant.role'])->group(function () {
        Route::post('/logout', [MerchantAuthController::class, 'logout']);
        Route::get('/profile', [MerchantAuthController::class, 'profile']);

        Route::get('/api-keys', [ApiKeyController::class, 'index']);
        Route::post('/api-keys', [ApiKeyController::class, 'store']);
        Route::delete('/api-keys/{id}', [ApiKeyController::class, 'destroy']);
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

    Route::post('/transfers', [MerchantTransferController::class, 'initiateTransfer'])
        ->middleware(['auth.apikey:transfer.write', 'idempotency.key']);
    Route::post('/withdrawals', [MerchantTransferController::class, 'initiateWithdrawal'])
        ->middleware(['auth.apikey:withdrawal.write', 'idempotency.key']);
    Route::post('/deposits', [MerchantTransferController::class, 'initiateDeposit'])
        ->middleware(['auth.apikey:deposit.write', 'idempotency.key']);

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
