<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\TransferService;
use App\Http\Controllers\Controller;
use App\Models\CountryService;
use App\Models\FeeRule;
use App\Models\Provider;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Providers de paiement (Digitwave, banques...). Désactiver un provider (`active=false`)
 * envoie immédiatement ses corridors en traitement manuel.
 *
 * Suppression : refusée tant que le provider sert (corridors, règles de frais, historique de
 * transactions) et pour le provider par défaut du mobile money. Supprimer ce dernier ferait
 * retomber le routage sur le comportement historique « tout en automatique » et contournerait
 * l'interrupteur de désactivation : la désactivation, elle, est toujours possible.
 */
class ProviderController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('manage', Provider::class);

        return response()->json(Provider::orderBy('name')->get()->map(fn (Provider $p) => $this->present($p)));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage', Provider::class);

        $provider = Provider::create($request->validate([
            'code' => 'required|string|max:50|regex:/^[a-z0-9_-]+$/|unique:providers,code',
            'name' => 'required|string|max:255',
            'services' => 'sometimes|nullable|array',
            'services.*' => ['string', Rule::enum(TransferService::class)],
            'active' => 'sometimes|boolean',
        ]));

        return response()->json(['status' => 'success', 'data' => $this->present($provider)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        Gate::authorize('manage', Provider::class);

        $provider = Provider::findOrFail($id);
        $provider->update($request->validate([
            'name' => 'sometimes|string|max:255',
            'services' => 'sometimes|nullable|array',
            'services.*' => ['string', Rule::enum(TransferService::class)],
            'active' => 'sometimes|boolean',
        ]));

        return response()->json(['status' => 'success', 'data' => $this->present($provider->fresh())]);
    }

    public function destroy(string $id): JsonResponse
    {
        Gate::authorize('manage', Provider::class);

        $provider = Provider::findOrFail($id);

        if ($provider->code === config('transfers.default_mobile_money_provider')) {
            return response()->json([
                'status' => 'error',
                'error_code' => 'PROVIDER_IS_DEFAULT',
                'message' => "« {$provider->name} » est le provider par défaut du mobile money : il ne peut pas être supprimé (désactivez-le pour envoyer ses transferts en traitement manuel).",
            ], 409);
        }

        $usage = array_filter([
            'corridor(s)' => CountryService::where('provider_id', $provider->id)->count(),
            'règle(s) de frais' => FeeRule::where('provider_id', $provider->id)->count(),
            'transaction(s)' => Transaction::where('provider_id', $provider->id)->count(),
        ]);

        if ($usage) {
            $detail = collect($usage)->map(fn ($n, $label) => "{$n} {$label}")->implode(', ');

            return response()->json([
                'status' => 'error',
                'error_code' => 'PROVIDER_IN_USE',
                'message' => "« {$provider->name} » est encore utilisé ({$detail}) : désactivez-le plutôt que de le supprimer.",
            ], 409);
        }

        $provider->delete();

        return response()->json(['status' => 'success', 'message' => 'Provider supprimé.']);
    }

    /** `implemented` : une classe d'exécution existe pour ce code (config/transfers.php) ; sinon le provider reste ignoré. */
    private function present(Provider $provider): array
    {
        $implemented = collect(config('transfers.gateways'))->contains(fn ($gateways) => array_key_exists($provider->code, $gateways));

        return $provider->toArray() + ['implemented' => $implemented];
    }
}
