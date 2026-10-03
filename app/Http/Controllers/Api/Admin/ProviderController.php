<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\TransferService;
use App\Http\Controllers\Controller;
use App\Models\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Providers de paiement (Digitwave, banques...). Désactiver un provider (`active=false`)
 * envoie immédiatement ses corridors en traitement manuel.
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

    /** `implemented` : une classe d'exécution existe pour ce code (config/transfers.php) ; sinon le provider reste ignoré. */
    private function present(Provider $provider): array
    {
        $implemented = collect(config('transfers.gateways'))->contains(fn ($gateways) => array_key_exists($provider->code, $gateways));

        return $provider->toArray() + ['implemented' => $implemented];
    }
}
