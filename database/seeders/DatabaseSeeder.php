<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(CountrySeeder::class);
        $this->call(ProviderSeeder::class);

        // Comptes de démonstration aux identifiants connus (superadmin / admin1234) :
        // jamais en production, l'admin réel se crée à la main (cf. deploy/README.md).
        if (! app()->environment('production')) {
            $this->call(UserSeeder::class);
        }
    }
}
