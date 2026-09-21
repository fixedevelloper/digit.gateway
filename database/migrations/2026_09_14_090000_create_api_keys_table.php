<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clés API des comptes marchands (usage serveur-à-serveur), distinctes des
     * tokens Sanctum de personal_access_tokens qui servent aux sessions
     * interactives (app Flutter, dashboard admin/marchand). Un marchand peut
     * avoir plusieurs clés (sandbox + production, rotation sans downtime).
     */
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Préfixe affiché en clair dans le dashboard pour identifier la clé
            // (ex: "sk_test_9f3a"). La valeur complète n'est jamais stockée.
            $table->string('key_prefix', 20);
            $table->string('key_hash', 64)->unique();
            $table->string('environment')->default('sandbox');
            $table->json('scopes')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
