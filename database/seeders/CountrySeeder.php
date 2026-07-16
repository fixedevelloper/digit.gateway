<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Operator;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    public function run(): void
    {
        // 1. Définition des pays et de leurs opérateurs réels rattachés
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
                        'name' => 'MTN Mobile Money',
                        'code' => 'MTN_CG',
                        'prefix_regex' => '^(06)[0-9]{7}$', // Numéros à 9 chiffres commençant par 06
                        'phone_length' => 9,
                        'min_amount' => 100.00,
                        'max_amount' => 1000000.00,
                        'fixed_fee' => 0.00,
                        'percent_fee' => 0.0200, // 2% par défaut
                    ],
                    [
                        'name' => 'Airtel Money',
                        'code' => 'AIRTEL_CG',
                        'prefix_regex' => '^(04|05)[0-9]{7}$', // Numéros à 9 chiffres commençant par 04 ou 05
                        'phone_length' => 9,
                        'min_amount' => 100.00,
                        'max_amount' => 1000000.00,
                        'fixed_fee' => 0.00,
                        'percent_fee' => 0.0200,
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
                        'prefix_regex' => '^(074|075|076|014)[0-9]{6}$', // Préfixes Vodacom Tanzanie
                        'phone_length' => 9,
                        'min_amount' => 500.00,
                        'max_amount' => 3000000.00,
                        'fixed_fee' => 100.00, // Frais fixes en TZS
                        'percent_fee' => 0.0150, // 1.5%
                    ],
                    [
                        'name' => 'Tigo Pesa',
                        'code' => 'TIGO_TZ',
                        'prefix_regex' => '^(065|067|071)[0-9]{6}$',
                        'phone_length' => 9,
                        'min_amount' => 500.00,
                        'max_amount' => 3000000.00,
                        'fixed_fee' => 100.00,
                        'percent_fee' => 0.0150,
                    ],
                    [
                        'name' => 'Airtel Money',
                        'code' => 'AIRTEL_TZ',
                        'prefix_regex' => '^(068|069|078)[0-9]{6}$',
                        'phone_length' => 9,
                        'min_amount' => 500.00,
                        'max_amount' => 3000000.00,
                        'fixed_fee' => 100.00,
                        'percent_fee' => 0.0150,
                    ],
                    [
                        'name' => 'Halopesa',
                        'code' => 'HALOTEL_TZ',
                        'prefix_regex' => '^(066|062)[0-9]{6}$',
                        'phone_length' => 9,
                        'min_amount' => 500.00,
                        'max_amount' => 3000000.00,
                        'fixed_fee' => 100.00,
                        'percent_fee' => 0.0150,
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
                        'fixed_fee' => $operatorData['fixed_fee'] ?? 0.00,
                        'percent_fee' => $operatorData['percent_fee'] ?? 0.0000,
                        'status' => true, // Activé par défaut pour les tests
                        'logo' => 'assets/operators/' . strtolower($operatorData['code']) . '.png' // Utile pour ton projet Flutter
                    ]
                );
            }
        }
    }
}
