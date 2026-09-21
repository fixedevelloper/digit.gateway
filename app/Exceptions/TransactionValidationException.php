<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Variante de ValidationException portant un `error_code` machine-readable
 * (INSUFFICIENT_FUNDS, INVALID_OPERATOR, AMOUNT_OUT_OF_BOUNDS...), utilisé par
 * Api\Merchant\TransferController pour construire une réponse d'erreur
 * exploitable par un client automatisé, en plus du message texte.
 *
 * Reste un sous-type de ValidationException : Api\TransferController (app
 * mobile) continue de la capturer via son `catch (ValidationException $e)`
 * existant, avec exactement le même message — aucun changement de comportement
 * côté mobile.
 */
class TransactionValidationException extends ValidationException
{
    public string $errorCode = 'VALIDATION_ERROR';

    public static function make(string $errorCode, string $field, string $message): static
    {
        /** @var static $exception */
        $exception = static::withMessages([$field => [$message]]);
        $exception->errorCode = $errorCode;

        return $exception;
    }
}
