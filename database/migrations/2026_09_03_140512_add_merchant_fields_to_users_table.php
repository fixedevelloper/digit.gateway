<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Champs nécessaires à la gestion des comptes marchands B2B (dashboard admin) :
     * un marchand est un User avec role='merchant', qui réutilise le wallet et
     * l'infrastructure de transactions déjà en place pour les clients mobile money.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->unique()->after('phone');
            $table->string('company_name')->nullable()->after('email');
            $table->string('environment')->default('sandbox')->after('company_name');
            $table->boolean('status')->default(true)->after('environment');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email', 'company_name', 'environment', 'status']);
        });
    }
};
