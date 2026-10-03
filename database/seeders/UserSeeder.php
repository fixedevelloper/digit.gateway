<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('UserSeeder ignoré en production (identifiants de démonstration).');

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | 1. COMPTE CLIENT / TEST PRINCIPAL (Lorenzo)
        |--------------------------------------------------------------------------
        */
        $user = User::updateOrCreate(
            ['phone' => '657285050'],
            [
                'name' => 'Lorenzo Mbah',
                'password' => Hash::make('password'),
                'transaction_pin' => Hash::make('0000'),
                'role' => 'customer', // Profil utilisateur classique pour tester l'app Flutter
            ]
        );

        Wallet::updateOrCreate(
            ['user_id' => $user->id],
            [
                'balance' => 750000.00, // 750 000 XAF pour les tests de flux
                'currency' => 'XAF',
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | 2. COMPTE SUPERADMIN (Console Next.js)
        |--------------------------------------------------------------------------
        */
        $admin = User::updateOrCreate(
            ['phone' => '670000000'], // Numéro admin de ton choix
            [
                'name' => 'Admin Digit-Gateway',
                'password' => Hash::make('admin1234'), // À modifier en production
                'transaction_pin' => Hash::make('9999'),
                'role' => 'superadmin', // Passe le check du middleware 'CheckAdminRole'
            ]
        );

        Wallet::updateOrCreate(
            ['user_id' => $admin->id],
            [
                'balance' => 0.00, // Pas besoin de fonds pour administrer, mais structurellement requis
                'currency' => 'XAF',
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | 3. COMPTE AGENT (traitement des transferts manuels)
        |--------------------------------------------------------------------------
        */
        User::updateOrCreate(
            ['phone' => '670000001'],
            [
                'name' => 'Agent Démo',
                'password' => Hash::make('agent1234'),
                'transaction_pin' => Hash::make('8888'),
                'role' => 'agent',
            ]
        );
    }
}
