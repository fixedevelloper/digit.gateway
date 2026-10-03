<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Transition de statut impossible (transfert déjà pris en charge, déjà terminé, preuve
 * manquante...) : répondu en 409 avec un code machine-readable.
 */
class InvalidTransferStateException extends Exception
{
    public function __construct(string $message, public readonly string $errorCode = 'INVALID_TRANSFER_STATE')
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], 409);
    }
}
