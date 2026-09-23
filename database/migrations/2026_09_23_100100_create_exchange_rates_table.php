<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Taux de change saisis manuellement par l'admin. Table en ajout seul : chaque
     * modification crée une nouvelle ligne, la plus récente d'une paire fait foi,
     * les précédentes servent d'historique d'audit.
     *
     * `rate` = nombre d'unités de `quote_currency` pour 1 unité de `base_currency`
     * (ex: base USD, quote XAF, rate 605 ⇒ 1 USD = 605 XAF).
     */
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('base_currency', 3);
            $table->string('quote_currency', 3);
            $table->decimal('rate', 20, 8);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['base_currency', 'quote_currency', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
