<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CountryServiceStatus;
use App\Enums\TransferService;
use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\CountryService;
use App\Models\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Services (MOBILE_MONEY, BANK_TRANSFER) d'un pays : statut ACTIVE / INACTIVE / MANUAL,
 * provider, bornes et plafonds. C'est ce que lit TransferRoutingService.
 */
class CountryServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage', Country::class);

        return response()->json(
            CountryService::with(['country:id,name,iso', 'provider:id,code,name,active'])
                ->when($request->query('country_id'), fn ($q, $id) => $q->where('country_id', $id))
                ->orderBy('country_id')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage', Country::class);

        $data = $request->validate([
            'country_id' => 'required|integer|exists:countries,id',
            'service' => ['required', Rule::enum(TransferService::class)],
            ...$this->rules(),
        ]);

        if (CountryService::where('country_id', $data['country_id'])->where('service', $data['service'])->exists()) {
            throw ValidationException::withMessages(['service' => ['Ce service est déjà configuré pour ce pays : utilisez la mise à jour.']]);
        }

        $this->assertProviderSupports($data['provider_id'] ?? null, $data['service']);

        return response()->json(['status' => 'success', 'data' => CountryService::create($data)->load('provider')], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        Gate::authorize('manage', Country::class);

        $config = CountryService::findOrFail($id);
        $data = $request->validate($this->rules(sometimes: true));

        $this->assertProviderSupports($data['provider_id'] ?? null, $config->service->value);
        $config->update($data);

        return response()->json(['status' => 'success', 'data' => $config->fresh()->load('provider')]);
    }

    public function destroy(string $id): JsonResponse
    {
        Gate::authorize('manage', Country::class);

        CountryService::findOrFail($id)->delete();

        return response()->json(['status' => 'success']);
    }

    private function rules(bool $sometimes = false): array
    {
        $s = $sometimes ? 'sometimes|' : '';

        return [
            'status' => [$sometimes ? 'sometimes' : 'required', Rule::enum(CountryServiceStatus::class)],
            'provider_id' => $s.'nullable|integer|exists:providers,id',
            'min_amount' => $s.'nullable|numeric|min:0',
            'max_amount' => $s.'nullable|numeric|gte:min_amount',
            'daily_limit' => $s.'nullable|numeric|min:0',
            'monthly_limit' => $s.'nullable|numeric|min:0',
        ];
    }

    private function assertProviderSupports(?int $providerId, string $service): void
    {
        if ($providerId && ! Provider::findOrFail($providerId)->supports($service)) {
            throw ValidationException::withMessages(['provider_id' => ["Ce provider ne supporte pas le service {$service}."]]);
        }
    }
}
