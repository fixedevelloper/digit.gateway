<?php

namespace Database\Seeders;

use App\Models\Provider;
use Illuminate\Database\Seeder;

class ProviderSeeder extends Seeder
{
    /**
     * Enregistre Digitwave pour que l'admin puisse le désactiver globalement
     * (les transferts Mobile Money passent alors en traitement manuel). Sans cette
     * ligne, le comportement historique (tout via Digitwave) s'applique. Idempotent.
     */
    public function run(): void
    {
        Provider::firstOrCreate(
            ['code' => 'digitwave'],
            ['name' => 'Digitwave', 'services' => ['MOBILE_MONEY'], 'active' => true]
        );
    }
}
