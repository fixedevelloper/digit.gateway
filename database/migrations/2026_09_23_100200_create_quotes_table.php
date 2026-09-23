<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cotation présentée au client avant validation (montant saisi dans la devise
     * du wallet, équivalent dans la devise de l'opérateur, frais, taux). Elle fige
     * le taux pendant une courte durée et ne peut servir qu'à une seule transaction :
     * le client est débité exactement de ce qu'il a vu et validé.
     */
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('operators')->cascadeOnDelete();
            $table->enum('type', ['transfer', 'withdrawal', 'deposit']);

            // Devise du wallet (ce que le client saisit et paie)
            $table->decimal('amount', 15, 2);
            $table->decimal('fee', 15, 2);
            $table->decimal('total', 15, 2);
            $table->string('currency', 3);

            // Devise de l'opérateur (ce qui est envoyé à Digitwave)
            $table->decimal('converted_amount', 15, 2);
            $table->decimal('converted_fee', 15, 2);
            $table->string('converted_currency', 3);

            // Unités de `currency` pour 1 unité de `converted_currency` (ex: 605 XAF/USD)
            $table->decimal('rate', 20, 8);

            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('operator_id')->nullable()->after('recipient_operator')->constrained('operators')->nullOnDelete();
            $table->uuid('quote_id')->nullable()->after('operator_id')->unique();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['quote_id']);
            $table->dropColumn('quote_id');
            $table->dropConstrainedForeignId('operator_id');
        });

        Schema::dropIfExists('quotes');
    }
};
