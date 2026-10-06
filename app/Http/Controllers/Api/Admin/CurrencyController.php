<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\FeeRule;
use App\Models\Operator;
use App\Models\Quote;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Devises disponibles (nom + symbole). Le code ISO n'est pas modifiable : il est référencé par les taux et opérateurs. */
class CurrencyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Currency::orderBy('code')->get());
    }

    public function store(Request $request): JsonResponse
    {
        // Normalisé AVANT la validation : l'unicité doit se vérifier sur « XAF », pas sur « xaf ».
        $request->merge(['code' => strtoupper((string) $request->input('code'))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'size:3', 'alpha', Rule::unique('currencies', 'code')],
            'name' => 'required|string|max:100',
            'symbol' => 'required|string|max:10',
        ]);

        return response()->json(['status' => 'success', 'data' => Currency::create($data)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $currency = Currency::findOrFail($id);
        $currency->update($request->validate([
            'name' => 'sometimes|string|max:100',
            'symbol' => 'sometimes|string|max:10',
        ]));

        return response()->json(['status' => 'success', 'data' => $currency]);
    }

    /**
     * Le code ISO est stocké tel quel (sans clé étrangère) dans les taux, opérateurs, pays, wallets,
     * règles de frais, cotations et transactions : supprimer une devise encore référencée laisserait des
     * données orphelines (ex. wallets en XAF sans devise XAF). La suppression est donc refusée dès qu'elle sert.
     */
    public function destroy(string $id): JsonResponse
    {
        $currency = Currency::findOrFail($id);
        $code = $currency->code;

        $usage = array_filter([
            'taux de change' => ExchangeRate::where('base_currency', $code)->orWhere('quote_currency', $code)->count(),
            'opérateur(s)' => Operator::where('currency', $code)->count(),
            'pays' => Country::where('currency', $code)->count(),
            'wallet(s)' => Wallet::where('currency', $code)->count(),
            'règle(s) de frais' => FeeRule::where('currency', $code)->count(),
            'cotation(s)' => Quote::where('currency', $code)->orWhere('converted_currency', $code)->count(),
            'transaction(s)' => Transaction::where('currency_sent', $code)->orWhere('currency_received', $code)->count(),
        ]);

        if ($usage) {
            $detail = collect($usage)->map(fn ($n, $label) => "{$n} {$label}")->implode(', ');

            return response()->json([
                'status' => 'error',
                'error_code' => 'CURRENCY_IN_USE',
                'message' => "La devise {$code} est encore utilisée ({$detail}) : elle ne peut pas être supprimée.",
            ], 409);
        }

        $currency->delete();

        return response()->json(['status' => 'success', 'message' => 'Devise supprimée.']);
    }
}
