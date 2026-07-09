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
        Schema::table('transactions', function (Blueprint $table) {
            // Clé étrangère vers la table agencies (nullable si toutes les transactions ne passent pas par une agence)
            $table->foreignId('agency_id')
                ->nullable()
                ->after('id') // Ajustez selon la position voulue
                ->constrained('agencies')
                ->nullOnDelete(); // Si une agence est supprimée, on garde l'historique de la transaction
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropColumn('agency_id');
        });
    }
};
