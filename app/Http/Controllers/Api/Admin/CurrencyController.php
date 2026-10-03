<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Operator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Devises disponibles (nom + symbole). Le code ISO n'est pas modifiable : il est référencé par les taux et opérateurs. */
class CurrencyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Currency::orderBy('code')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:3', 'alpha', Rule::unique('currencies', 'code')],
            'name' => 'required|string|max:100',
            'symbol' => 'required|string|max:10',
        ]);
        $data['code'] = strtoupper($data['code']);

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

    public function destroy(string $id): JsonResponse
    {
        $currency = Currency::findOrFail($id);

        $used = ExchangeRate::where('base_currency', $currency->code)->orWhere('quote_currency', $currency->code)->exists()
            || Operator::where('currency', $currency->code)->exists();
        if ($used) {
            throw ValidationException::withMessages(['code' => ['Cette devise est utilisée par des taux de change ou des opérateurs.']]);
        }

        $currency->delete();

        return response()->json(['status' => 'success']);
    }
}
