<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distingue le canal d'origine d'une transaction : app mobile (Sanctum) ou
     * intégration marchande serveur-à-serveur (clé API, /v1/gateway/*). Sert à
     * l'audit admin et à ce qu'un marchand ne voie que ses propres transactions API.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('channel')->default('mobile_app')->after('type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
