<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('phone')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('phone')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
         Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('iso')->unique();
            $table->string('iso3')->nullable();
            $table->string('currency')->nullable();
            $table->integer('numcode')->nullable();
            $table->integer('phonecode');
            $table->boolean('status')->default(false);
            $table->string('flag')->nullable();
            $table->timestamps();
        });
        Schema::create('operators', function (Blueprint $table) {
            $table->id();
            
            // Relation forte avec la table countries
            $table->foreignId('country_id')->constrained('countries')->onDelete('cascade');

            // Identifiants de l'opérateur
            $table->string('name'); // Nom commercial (ex: MTN, Orange, Airtel, Vodacom, Tigo)
            $table->string('code')->index(); // Code unique de routage technique (ex: MTN_CM, ORANGE_CI, AIRTEL_TZ)
            
            // Configuration des flux Mobile Money
            $table->string('logo')->nullable(); // URL ou chemin de l'icône de l'opérateur pour l'UI Flutter
            $table->boolean('status')->default(true)->index(); // Permet d'activer/couper un réseau instantanément en prod

            // Règles de validation des numéros (Évite les requêtes API futiles vers l'agrégateur en cas de mauvais numéro)
            $table->string('prefix_regex')->nullable(); // Regex des préfixes acceptés (ex: ^(67|68|650|651|652))
            $table->integer('phone_length')->default(9); // Longueur attendue du numéro local (ex: 9 au CM, 10 en CI)

            // Paramètres financiers spécifiques au réseau
            $table->decimal('min_amount', 15, 2)->default(100.00); // Montant minimum par transaction sur ce réseau
            $table->decimal('max_amount', 15, 2)->default(500000.00); // Montant maximum (limites réglementaires de l'opérateur)

            $table->timestamps();

            // Un opérateur doit avoir un code unique global ou par pays
            $table->unique(['country_id', 'code']);
        });
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            // Utilisation de decimal pour éviter les erreurs d'arrondi sur les montants financiers
            $table->decimal('balance', 15, 2)->default(0.00);
            $table->string('currency', 3)->default('XAF');
            $table->timestamps();
        });
        Schema::create('recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name'); // Nom du bénéficiaire
            $table->string('phone'); // Téléphone Mobile Money du destinataire
            $table->string('operator'); // ex: 'ORANGE', 'MTN', 'WAVE', 'MOOV'
            $table->string('country_code', 2); // ex: 'CM', 'CI', 'SN'
            $table->timestamps();
        });
       Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            
            // Indexation & Identifiants Uniques
            $table->string('reference')->unique(); // Référence interne unique (ex: TX-20260708-XXXX)
            $table->string('gateway_reference')->nullable()->index(); // ID reçu du fournisseur (MTN, Orange, Guens Africa, Flutterwave)

            // Catégorisation de la transaction (La clé manquante)
            // 'transfer' (Envoi standard), 'deposit' (Dépôt/Cash-in), 'withdrawal' (Retrait/Cash-out), 'payment' (Paiement marchand)
            $table->enum('type', ['transfer', 'deposit', 'withdrawal', 'payment'])->default('transfer')->index();

            // Acteurs de la transaction
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // L'expéditeur ou l'initiateur
         // Remplace 'users' par 'recipients'
           $table->foreignId('recipient_id')->nullable()->constrained('recipients')->onDelete('set null');// Bénéficiaire interne si inscrit

            // Données brutes du bénéficiaire (Historisation obligatoire en cas de changement de profil ou si externe)
            $table->string('recipient_name')->nullable(); // Nom affiché lors de l'envoi pour l'audit
            $table->string('recipient_phone', 20)->index(); // Format international (ex: +2376xxxxxxxx)
            $table->string('recipient_operator', 50); // MTN, ORANGE, WAVE, COMPTE_INTERNE

            // Détails financiers (Montant débité)
            $table->decimal('amount_sent', 15, 2); 
            $table->string('currency_sent', 3)->default('XAF');
             $table->string('country_name', 240)->default('');

            // Ventilation précise des frais (Crucial pour la comptabilité et les rapports de marge)
            $table->decimal('fees', 15, 2)->default(0.00); // Frais totaux facturés au client
            $table->decimal('agent_commission', 15, 2)->default(0.00); // Si initié via un point de vente physique
            $table->decimal('gateway_fees', 15, 2)->default(0.00); // Ce que l'opérateur mobile vous facture en tâche de fond

            // Logique de conversion de devises
            $table->decimal('exchange_rate', 10, 6)->default(1.000000); // Précision portée à 6 décimales pour l'Euro/XAF

            // Détails financiers (Montant crédité)
            $table->decimal('amount_to_receive', 15, 2); 
            $table->string('currency_received', 3)->default('XAF');

            // États de la transaction
            $table->enum('status', ['pending', 'processing', 'success', 'failed', 'reversed'])->default('pending')->index();

            // Gestion des erreurs et Audit
            $table->string('failure_code')->nullable(); // Code d'erreur standardisé (ex: INSUFFICIENT_FUNDS, TIMEOUT)
            $table->text('failure_reason')->nullable(); // Message d'erreur explicite pour le support technique

            // Sécurité & Traçabilité (Conformité KYC / Lutte anti-blanchiment)
            $table->string('ip_address', 45)->nullable(); // Adresse IP de l'appareil (IPv4 ou IPv6)
            $table->string('device_signature')->nullable(); // Empreinte de l'appareil pour la détection de fraudes
            
            // Horodatage de validation financière exacte
            $table->timestamp('completed_at')->nullable()->index(); // Date exacte du succès ou de l'échec (distinct du timestamp Laravel)
            $table->timestamps();

            // Index composites pour accélérer les requêtes de l'historique de l'application
            $table->index(['user_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
