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
        // 1. Création de l'utilisateur de test principal
        $user = User::updateOrCreate(
            ['phone' => '657285050'],
            [
                'name' => 'Lorenzo Mbah',
                'password' => Hash::make('password'),
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

        // 3. Ajout de bénéficiaires récurrents
        $recipient1 = Recipient::create([
            'user_id' => $user->id,
            'name' => 'Jean Mobile',
            'phone' => '699887766',
            'operator' => 'ORANGE',
            'country_code' => 'CM',
        ]);

        $recipient2 = Recipient::create([
            'user_id' => $user->id,
            'name' => 'Idriss Diallo',
            'phone' => '0707112233',
            'operator' => 'MTN',
            'country_code' => 'CI',
        ]);

        // 4. Génération d'un historique de transactions diversifié pour l'UI Flutter

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
            
            // Éléments financiers (Frais globaux de 500 : 350 pour nous, 150 pour l'opérateur)
            'amount_sent' => 50000.00,
            'currency_sent' => 'XAF',
            'fees' => 500.00,
            'agent_commission' => 0.00,
            'gateway_fees' => 150.00, 
            'exchange_rate' => 1.000000,
            'amount_to_receive' => 49500.00,
            'currency_received' => 'XAF',
            
            'status' => 'success',
            'ip_address' => '102.244.40.15', // Simule une IP au Cameroun
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
            
            // XAF vers XOF (Parité fixe 1:1, mais frais internationaux un peu plus élevés)
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
            'completed_at' => null, // Toujours nul tant que l'agrégateur n'a pas répondu
            'created_at' => Carbon::now()->subMinutes(15),
        ]);

        // Transaction 3 : Échouée (Bénéficiaire externe non enregistré)
        Transaction::create([
            'reference' => 'TX-' . date('Ymd') . '-' . strtoupper(Str::random(6)),
            'gateway_reference' => 'MTN-CM-FAILED-772',
            'type' => 'transfer',
            'user_id' => $user->id,
            'recipient_id' => null, // Pas lié à un bénéficiaire sauvé
            'recipient_name' => 'Inconnu MTN', // Saisi manuellement par l'utilisateur
            'recipient_phone' => '670112233',
            'recipient_operator' => 'MTN',
            
            'amount_sent' => 25000.00,
            'currency_sent' => 'XAF',
            'fees' => 250.00,
            'agent_commission' => 0.00,
            'gateway_fees' => 0.00, // Souvent 0 si l'opérateur rejette immédiatement sans tarifer
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
            'type' => 'withdrawal', // Changement de type ici
            'user_id' => $user->id,
            'recipient_id' => null,
            'recipient_name' => $user->name, // C'est lui-même qui reçoit sur son solde de l'appli
            'recipient_phone' => $user->phone,
            'recipient_operator' => 'ORANGE',

            'amount_sent' => 200000.00,
            'currency_sent' => 'XAF',
            'fees' => 0.00, // Pas de frais sur les dépôts pour inciter l'usage
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