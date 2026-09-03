<?php

namespace App\Providers;

use App\Contracts\PaymentGatewayContract;
use App\Services\Gateways\DigitwaveGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Seul fournisseur de paiement pour l'instant. Le jour où un 2e opérateur
        // s'ajoute (MTN MoMo, Orange Money...), ce binding devient un résolveur
        // par pays/opérateur plutôt qu'un mapping fixe vers une seule classe.
        $this->app->bind(PaymentGatewayContract::class, DigitwaveGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Limite stricte sur les endpoints d'authentification pour freiner le brute-force
        // (par IP + numéro de téléphone visé, pour éviter qu'un attaquant sur une seule IP
        // ne puisse tester des mots de passe sur des dizaines de comptes en restant sous la limite par IP).
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip().'|'.$request->input('phone'));
        });
    }
}
