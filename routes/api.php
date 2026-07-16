<?php

use App\Http\Controllers\Api\Admin\OperatorController;
use App\Http\Controllers\Api\Admin\TransactionController;
use App\Http\Controllers\Api\Admin\WalletController;
use App\Http\Controllers\Api\SecurityController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\AuthController;
 use App\Http\Controllers\Api\UserController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ==========================================
// 1. ROUTE WEBHOOK (Callback externe)
// ==========================================


// ==========================================
// 2. ROUTES D'AUTHENTIFICATION UTILISATEUR
// ==========================================
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/auth/login', [SecurityController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    // Si ton application Flutter utilise des Tokens Sanctum, garde cette ligne.
    // Sinon, déplace-la dans le groupe "ApiKey" ci-dessous si le profil dépend uniquement de l'ApiKey.
    Route::get('/profile', [AuthController::class, 'profile']);

    //Route::get('/profile', [UserController::class, 'getProfile']);
    Route::post('/profile/update', [UserController::class, 'updateProfile']);
    Route::post('/profile/update-pin', [UserController::class, 'updateCodePin']);
    Route::post('/profile/change-password', [UserController::class, 'changePassword']);

});


// ==========================================
// 3. ROUTES SÉCURISÉES PAR API KEY (Flutter App)
// ==========================================
Route::middleware('auth:sanctum')->group(function () {

    // Pays et opérateurs
    Route::get('/countries', [CountryController::class, 'index']);
    Route::get('/countries/{iso}', [CountryController::class, 'show']);

    // Transactions (Payout & Cash-In)
    Route::post('/transfer', [TransferController::class, 'initiateTransfer']);
    Route::post('/withdrawal', [TransferController::class, 'initiateWithdrawal']);
     Route::post('/deposit', [TransferController::class, 'initiateDeposit']);

    // Vérification de statut et historique
    Route::get('/get_request', [TransferController::class, 'checkStatus']);
    Route::get('/transactions', [TransferController::class, 'recentTransactions']); // Ajouté pour correspondre à ton ApiClient
    Route::get('/history', [TransferController::class, 'historyList']);            // Ajouté pour correspondre à ton ApiClient


// Si tes routes sont protégées par sanctum :

    Route::get('/transactions/{id}/status', [TransferController::class, 'getTransactionStatus']);

    // Si ton /profile dépend uniquement de l'ApiKey (et non de Sanctum), décommentes cette ligne :
    // Route::get('/profile', [AuthController::class, 'profile']);
});





/*
|--------------------------------------------------------------------------
| Routes Privées - Console d'Administration (Sécurisées par Sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {

    // Déconnexion de la session admin
    Route::post('/auth/logout', [SecurityController::class, 'logout']);

    // Gestion des Opérateurs (Kill switch, modification des frais fixes & % )
    Route::get('/operators', [OperatorController::class, 'index']);
    Route::put('/operators/{id}', [OperatorController::class, 'update']);

    // Gestion des Pays / Corridors régionaux
    Route::get('/countries', [\App\Http\Controllers\Api\Admin\CountryController::class, 'index']);
    Route::put('/countries/{id}', [\App\Http\Controllers\Api\Admin\CountryController::class, 'update']);

    // Gestion & Audit de la Masse Monétaire (Wallets)
    Route::get('/wallets', [WalletController::class, 'index']);
    Route::post('/wallets/{id}/adjust', [WalletController::class, 'adjust']); // Mutation d'ajustement manuel

    // Journal d'Audit Global (Transactions de la passerelle)
    Route::get('/transactions', [TransactionController::class, 'index']);

});
