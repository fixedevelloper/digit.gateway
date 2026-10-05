<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Controller;
use App\Jobs\SendWebhookDelivery;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookDispatcher;
use App\Services\Webhooks\WebhookUrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gestion des webhooks du marchand connecté (portail). Le secret de signature n'est
 * retourné qu'à la création.
 */
class WebhookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $request->user()->webhookEndpoints()->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request, WebhookUrlGuard $guard): JsonResponse
    {
        $merchant = $request->user();

        $data = $request->validate([
            'url' => 'required|url|max:2048',
            'environment' => ['required', Rule::in(['sandbox', 'production'])],
        ]);

        if ($data['environment'] === 'production' && $merchant->environment !== 'production') {
            return response()->json([
                'status' => 'error',
                'message' => "Votre compte n'est pas encore activé en production.",
            ], 403);
        }

        if ($merchant->webhookEndpoints()->count() >= WebhookEndpoint::MAX_PER_MERCHANT) {
            return response()->json([
                'status' => 'error',
                'message' => 'Maximum '.WebhookEndpoint::MAX_PER_MERCHANT.' URL de webhook.',
            ], 422);
        }

        $check = $guard->inspect($data['url']);
        if (! $check['ok']) {
            return response()->json(['status' => 'error', 'message' => $check['error']], 422);
        }

        $secret = WebhookEndpoint::generateSecret();
        $endpoint = $merchant->webhookEndpoints()->create($data + ['secret' => $secret]);

        return response()->json([
            'status' => 'success',
            'message' => 'Webhook créé. Copiez le secret maintenant : il ne sera plus affiché.',
            'data' => $endpoint->toArray() + ['secret' => $secret],
        ], 201);
    }

    public function update(Request $request, string $id, WebhookUrlGuard $guard): JsonResponse
    {
        $endpoint = $request->user()->webhookEndpoints()->findOrFail($id);

        $data = $request->validate([
            'url' => 'sometimes|url|max:2048',
            'active' => 'sometimes|boolean',
        ]);

        if (isset($data['url']) && ! ($check = $guard->inspect($data['url']))['ok']) {
            return response()->json(['status' => 'error', 'message' => $check['error']], 422);
        }

        $endpoint->update($data);

        return response()->json(['status' => 'success', 'data' => $endpoint]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $request->user()->webhookEndpoints()->findOrFail($id)->delete();

        return response()->json(['status' => 'success', 'message' => 'Webhook supprimé.']);
    }

    /** Envoie un événement `ping` pour vérifier l'URL et la validation de signature. */
    public function test(Request $request, string $id, WebhookDispatcher $dispatcher): JsonResponse
    {
        $endpoint = $request->user()->webhookEndpoints()->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $dispatcher->ping($endpoint)->fresh(),
        ], 202);
    }

    public function deliveries(Request $request, string $id): JsonResponse
    {
        $endpoint = $request->user()->webhookEndpoints()->findOrFail($id);

        return response()->json($endpoint->deliveries()->latest('id')->paginate(30));
    }

    /** Rejoue une livraison échouée (ou n'importe quelle livraison terminée). */
    public function redeliver(Request $request, string $id): JsonResponse
    {
        $delivery = WebhookDelivery::whereHas('endpoint', fn ($q) => $q->where('user_id', $request->user()->id))
            ->findOrFail($id);

        if ($delivery->status === WebhookDelivery::PENDING) {
            return response()->json(['status' => 'error', 'message' => 'Livraison déjà en cours.'], 409);
        }

        $delivery->update(['status' => WebhookDelivery::PENDING, 'attempts' => 0, 'last_error' => null, 'next_retry_at' => null]);
        SendWebhookDelivery::dispatch($delivery);

        return response()->json(['status' => 'success', 'data' => $delivery->fresh()], 202);
    }
}
