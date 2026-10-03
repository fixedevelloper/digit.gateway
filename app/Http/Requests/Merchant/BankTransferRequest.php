<?php

namespace App\Http\Requests\Merchant;

use App\Http\Requests\BankTransferRequest as MobileBankTransferRequest;

// Même validation que l'app mobile (champs bancaires obligatoires selon le pays), sans PIN :
// la clé API est le secret serveur-à-serveur. (Commentaire de ligne : un docblock serait
// publié tel quel dans la description du schéma OpenAPI.)
class BankTransferRequest extends MobileBankTransferRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['pin']);

        return $rules;
    }
}
