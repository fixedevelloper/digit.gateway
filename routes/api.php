<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\AuthController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ==========================================
// 1. ROUTE WEBHOOK (Callback externe)
// ==========================================
// Doit rester publique et en dehors de toute restriction d'API Key
Route::post('/v1/callback', [WebhookController::class, 'handleCallback']);


// ==========================================
// 2. ROUTES D'AUTHENTIFICATION UTILISATEUR
// ==========================================
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    // Si ton application Flutter utilise des Tokens Sanctum, garde cette ligne.
    // Sinon, déplace-la dans le groupe "ApiKey" ci-dessous si le profil dépend uniquement de l'ApiKey.
    Route::get('/profile', [AuthController::class, 'profile']); 
});


// ==========================================
// 3. ROUTES SÉCURISÉES PAR API KEY (Flutter App)
// ==========================================
// Remplacer 'check.apikey' par le nom exact de ton middleware de vérification de clé API
Route::middleware('auth:sanctum')->group(function () {
    
    // Pays et opérateurs
    Route::get('/countries', [CountryController::class, 'index']);
    Route::get('/countries/{iso}', [CountryController::class, 'show']);

    // Transactions (Payout & Cash-In)
    Route::post('/transfer', [TransferController::class, 'initiateTransfer']);
    Route::post('/withdrawal', [TransferController::class, 'initiateWithdrawal']);

    // Vérification de statut et historique
    Route::get('/get_request', [TransferController::class, 'checkStatus']);
    Route::get('/transactions', [TransferController::class, 'recentTransactions']); // Ajouté pour correspondre à ton ApiClient
    Route::get('/history', [TransferController::class, 'historyList']);            // Ajouté pour correspondre à ton ApiClient


// Si tes routes sont protégées par sanctum :

    Route::get('/transactions/{id}/status', [TransferController::class, 'getTransactionStatus']);

    // Si ton /profile dépend uniquement de l'ApiKey (et non de Sanctum), décommentes cette ligne :
    // Route::get('/profile', [AuthController::class, 'profile']);
});


// ==========================================
// 4. ROUTES D'ADMINISTRATION (Back-office)
// ==========================================
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/countries', [CountryController::class, 'store']);
    Route::patch('/countries/{id}/toggle-status', [CountryController::class, 'toggleStatus']);
});