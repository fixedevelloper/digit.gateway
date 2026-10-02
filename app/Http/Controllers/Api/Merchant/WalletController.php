<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Merchant Gateway', weight: 1)]
class WalletController extends Controller
{
    /**
     * Consulter le solde
     *
     * Solde disponible dans l'environnement de la clé utilisée : solde réel avec une
     * clé `sk_live_`, solde de test avec une clé `sk_test_`. Nécessite le scope `wallet.read`.
     */
    public function show(Request $request): JsonResponse
    {
        $environment = $request->attributes->get('environment') === 'production' ? 'production' : 'sandbox';
        $wallet = $request->user()->wallet;

        return response()->json([
            'status' => 'success',
            'data' => [
                'environment' => $environment,
                'balance' => (float) $wallet->{Wallet::balanceColumn($environment)},
                'currency' => $wallet->currency,
            ],
        ], 200);
    }
}
