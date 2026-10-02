<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Moment où la transaction a été soumise à Digitwave. Posé sous verrou juste avant
     * l'appel : une transaction déjà soumise n'est jamais renvoyée (relance du job) ni
     * remboursée automatiquement sans statut confirmé par Digitwave.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('gateway_reference');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('submitted_at');
        });
    }
};
