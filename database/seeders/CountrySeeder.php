<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Operator;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    public function run(): void
    {
        // 1. Définition des pays et de leurs opérateurs rattachés
        $countriesWithOperators = [
            [
                'country' => [
                    'name' => 'Republic of Congo',
                    'iso' => 'CG',
                    'iso3' => 'COG',
                    'currency' => 'XAF',
                    'numcode' => 178,
                    'phonecode' => 242,
                    'status' => true,
                ],
                'operators' => [
                    [
                        'name' => 'RESEAU CHARISMATIQUE',
                        'code' => 'RESEAU_CHARISMATIQUE',
                        'prefix_regex' => '^(06)', // Les numéros MTN commencent généralement par 06 au Congo
                        'phone_length' => 9,
                        'min_amount' => 100.00,
                        'max_amount' => 500000.00,
                    ]
            
                ]
            ],
            [
                'country' => [
                    'name' => 'Tanzania',
                    'iso' => 'TZ',
                    'iso3' => 'TZA',
                    'currency' => 'TZS',
                    'numcode' => 834,
                    'phonecode' => 255,
                    'status' => true,
                ],
                'operators' => [
                    [
                        'name' => 'Vodacom M-Pesa',
                        'code' => 'VODACOM_TZ',
                        'prefix_regex' => '^(074|075|076)', // Préfixes classiques Vodacom
                        'phone_length' => 9, // Format local hors indicatif (ex: 74xxxxxxx)
                        'min_amount' => 500.00, // En TZS, les montants nominaux sont plus élevés
                        'max_amount' => 3000000.00,
                    ],
                    [
                        'name' => 'Tigo Pesa',
                        'code' => 'TIGO_TZ',
                        'prefix_regex' => '^(065|067|071)',
                        'phone_length' => 9,
                        'min_amount' => 500.00,
                        'max_amount' => 3000000.00,
                    ],
                    [
                        'name' => 'Airtel Money',
                        'code' => 'AIRTEL_TZ',
                        'prefix_regex' => '^(068|069|078)',
                        'phone_length' => 9,
                        'min_amount' => 500.00,
                        'max_amount' => 3000000.00,
                    ]
                ]
            ]
        ];

        // 2. Traitement et injection en base de données
        foreach ($countriesWithOperators as $data) {
            // Création ou mise à jour du pays
            $country = Country::updateOrCreate(
                ['iso' => $data['country']['iso']], 
                $data['country']
            );

            // Injection de ses opérateurs liés
            foreach ($data['operators'] as $operatorData) {
                Operator::updateOrCreate(
                    [
                        'country_id' => $country->id,
                        'code' => $operatorData['code']
                    ],
                    [
                        'name' => $operatorData['name'],
                        'prefix_regex' => $operatorData['prefix_regex'],
                        'phone_length' => $operatorData['phone_length'],
                        'min_amount' => $operatorData['min_amount'],
                        'max_amount' => $operatorData['max_amount'],
                        'status' => true, // Activé par défaut pour les tests
                        'logo' => 'assets/operators/' . strtolower($operatorData['code']) . '.png' // Utile pour ton projet Flutter
                    ]
                );
            }
        }
    }
}