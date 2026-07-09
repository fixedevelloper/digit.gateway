<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Wallet;
use App\Models\Recipient;
use App\Models\Transaction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Création ou mise à jour forcée de l'utilisateur de test principal
        $user = User::updateOrCreate(
            ['phone' => '657285050'],
            [
                'name' => 'Lorenzo Mbah',
                'password' => Hash::make('password'),
                // Forcé en Bcrypt ici, écrasera définitivement toute valeur brute résiduelle
                'transaction_pin' => Hash::make('0000'), 
            ]
        );

        // 2. Assignation de son portefeuille financier
        Wallet::updateOrCreate(
            ['user_id' => $user->id],
            [
                'balance' => 750000.00, // 750 000 XAF pour les tests de flux
                'currency' => 'XAF',
            ]
        );

        // 3. Ajout de bénéficiaires récurrents (sécurisés contre la duplication)
        $recipient1 = Recipient::firstOrCreate(
            ['user_id' => $user->id, 'phone' => '699887766'],
            [
                'name' => 'Jean Mobile',
                'operator' => 'ORANGE',
                'country_code' => 'CM',
            ]
        );

        $recipient2 = Recipient::firstOrCreate(
            ['user_id' => $user->id, 'phone' => '0707112233'],
            [
                'name' => 'Idriss Diallo',
                'operator' => 'MTN',
                'country_code' => 'CI',
            ]
        );

        // 4. Génération d'un historique de transactions diversifié pour l'UI Flutter
        // On nettoie l'ancien historique de cet utilisateur pour éviter le flood à chaque seed
        Transaction::where('user_id', $user->id)->delete();

        // Transaction 1 : Réussie (Transfert National Orange Money)
        Transaction::create([
            'reference' => 'TX-' . date('Ymd') . '-' . strtoupper(Str::random(6)),
            'gateway_reference' => 'OM-MP-998231',
            'type' => 'transfer',
            'user_id' => $user->id,
            'recipient_id' => $recipient1->id,
            'recipient_name' => $recipient1->name,
            'recipient_phone' => $recipient1->phone,
            'recipient_operator' => $recipient1->operator,
            
            'amount_sent' => 50000.00,
            'currency_sent' => 'XAF',
            'fees' => 500.00,
            'agent_commission' => 0.00,
            'gateway_fees' => 150.00, 
            'exchange_rate' => 1.000000,
            'amount_to_receive' => 49500.00,
            'currency_received' => 'XAF',
            
            'status' => 'success',
            'ip_address' => '102.244.40.15',
            'device_signature' => 'iOS-iPhone15Pro-Build2026',
            'completed_at' => Carbon::now()->subHours(2),
            'created_at' => Carbon::now()->subHours(2)->subMinutes(5),
        ]);

        // Transaction 2 : En cours / Processing (Transfert International vers la Côte d'Ivoire)
        Transaction::create([
            'reference' => 'TX-' . date('Ymd') . '-' . strtoupper(Str::random(6)),
            'gateway_reference' => 'FLW-INT-88392',
            'type' => 'transfer',
            'user_id' => $user->id,
            'recipient_id' => $recipient2->id,
            'recipient_name' => $recipient2->name,
            'recipient_phone' => $recipient2->phone,
            'recipient_operator' => $recipient2->operator,
            
            'amount_sent' => 100000.00,
            'currency_sent' => 'XAF',
            'fees' => 1500.00,
            'agent_commission' => 0.00,
            'gateway_fees' => 450.00,
            'exchange_rate' => 1.000000,
            'amount_to_receive' => 98500.00,
            'currency_received' => 'XOF',
            
            'status' => 'processing',
            'ip_address' => '102.244.40.15',
            'device_signature' => 'iOS-iPhone15Pro-Build2026',
            'completed_at' => null,
            'created_at' => Carbon::now()->subMinutes(15),
        ]);

        // Transaction 3 : Échouée (Bénéficiaire externe non enregistré)
        Transaction::create([
            'reference' => 'TX-' . date('Ymd') . '-' . strtoupper(Str::random(6)),
            'gateway_reference' => 'MTN-CM-FAILED-772',
            'type' => 'transfer',
            'user_id' => $user->id,
            'recipient_id' => null,
            'recipient_name' => 'Inconnu MTN',
            'recipient_phone' => '670112233',
            'recipient_operator' => 'MTN',
            
            'amount_sent' => 25000.00,
            'currency_sent' => 'XAF',
            'fees' => 250.00,
            'agent_commission' => 0.00,
            'gateway_fees' => 0.00,
            'exchange_rate' => 1.000000,
            'amount_to_receive' => 24750.00,
            'currency_received' => 'XAF',
            
            'status' => 'failed',
            'failure_code' => 'ACCOUNT_RESTRICTED',
            'failure_reason' => 'Le compte du destinataire est bloqué ou restreint par l\'opérateur.',
            'ip_address' => '102.244.40.15',
            'device_signature' => 'iOS-iPhone15Pro-Build2026',
            'completed_at' => Carbon::now()->subDays(1),
            'created_at' => Carbon::now()->subDays(1)->subMinutes(2),
        ]);

        // Transaction 4 : Exemple de dépôt réussi (Cash-in pour alimenter le portefeuille)
        Transaction::create([
            'reference' => 'DEP-' . date('Ymd') . '-' . strtoupper(Str::random(6)),
            'gateway_reference' => 'OM-CASHIN-1122',
            'type' => 'withdrawal',
            'user_id' => $user->id,
            'recipient_id' => null,
            'recipient_name' => $user->name,
            'recipient_phone' => $user->phone,
            'recipient_operator' => 'ORANGE',

            'amount_sent' => 200000.00,
            'currency_sent' => 'XAF',
            'fees' => 0.00,
            'agent_commission' => 0.00,
            'gateway_fees' => 100.00,
            'exchange_rate' => 1.000000,
            'amount_to_receive' => 200000.00,
            'currency_received' => 'XAF',

            'status' => 'success',
            'ip_address' => '102.244.40.15',
            'device_signature' => 'iOS-iPhone15Pro-Build2026',
            'completed_at' => Carbon::now()->subDays(2),
            'created_at' => Carbon::now()->subDays(2)->subMinutes(1),
        ]);
    }
}