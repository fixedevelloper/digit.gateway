<?php

use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\MerchantController;
use App\Http\Controllers\Api\Admin\OperatorController;
use App\Http\Controllers\Api\Admin\TransactionController;
use App\Http\Controllers\Api\Admin\WalletController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\SecurityController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\UserController;
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
        Route::get('/dashboard/stats', [DashboardController::class, 'getStats']);

        // Gestion des Marchands B2B (intégrateurs de la passerelle)
        Route::get('/merchants', [MerchantController::class, 'index']);
        Route::put('/merchants/{id}', [MerchantController::class, 'update']);
    });
};

// Routes historiques, sans préfixe de version.
$registerApiRoutes();

// Alias versionné /api/v1/* — strictement les mêmes routes.
Route::prefix('v1')->group($registerApiRoutes);
