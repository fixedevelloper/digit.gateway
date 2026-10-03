<?php

namespace App\Services\Banking;

use App\Enums\CountryServiceStatus;
use App\Enums\TransferService;
use App\Models\Country;
use Illuminate\Support\Collection;

/**
 * Pays où le virement bancaire est ouvert (service BANK_TRANSFER ACTIVE ou MANUAL,
 * pays actif) avec les champs du bénéficiaire à fournir. Partagé par l'app mobile et
 * l'API marchande.
 */
class BankCountryCatalog
{
    public function __construct(private readonly BankFieldRequirements $requirements)
    {
    }

    /**
     * @return Collection<int, Country>
     */
    private function countries(): Collection
    {
        return Country::active()
            ->whereHas('services', fn ($q) => $q->where('service', TransferService::BankTransfer->value)
                ->whereIn('status', [CountryServiceStatus::Active->value, CountryServiceStatus::Manual->value]))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, array{id:int,name:string,iso:string,currency:?string,flag:?string,required_fields:array<int,string>}>
     */
    public function available(): Collection
    {
        return $this->countries()->map(fn (Country $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'iso' => $c->iso,
            'currency' => $c->currency,
            'flag' => $c->flag,
            'required_fields' => $this->requirements->requiredFor($c),
        ])->values();
    }
}
