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
        Schema::table('countries', function (Blueprint $table) {
            // Permet à l'admin de forcer manuellement, sans redéploiement, l'opérateur
            // réellement utilisé pour router les transactions de ce pays (ex: bascule
            // d'urgence si un opérateur mobile money est indisponible).
            $table->foreignId('forced_operator_id')
                ->nullable()
                ->after('status')
                ->constrained('operators')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('forced_operator_id');
        });
    }
};
