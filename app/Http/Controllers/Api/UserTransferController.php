<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransferResource;
use App\Models\Transaction;
use App\Services\ManualTransferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UserTransferController extends Controller
{
    /**
     * Mes transferts (Mobile Money et virements bancaires), du plus récent au plus ancien.
     */
    public function index(Request $request)
    {
        return TransferResource::collection(
            Transaction::where('user_id', $request->user()->id)
                ->where('type', 'transfer')
                ->with('bankBeneficiary')
                ->latest('id')
                ->paginate(20)
        );
    }

    public function show(Transaction $transfer): TransferResource
    {
        Gate::authorize('own', $transfer);

        return new TransferResource($transfer->load('bankBeneficiary'));
    }

    /**
     * Annuler un transfert manuel pas encore pris en charge par un agent : les fonds sont rendus.
     */
    public function cancel(Request $request, Transaction $transfer, ManualTransferService $manual): TransferResource
    {
        Gate::authorize('cancel', $transfer);

        return new TransferResource($manual->cancel($transfer, $request->user())->load('bankBeneficiary'));
    }
}
