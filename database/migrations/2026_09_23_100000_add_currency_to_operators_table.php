<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Devise dans laquelle l'opérateur reçoit/verse le montant (celle envoyée à
     * Digitwave). Un même code opérateur peut ainsi exister plusieurs fois dans un
     * pays, une fois par devise (ex: VODACOM_CD en CDF et en USD).
     *
     * Les opérateurs existants passent en 'XAF' (devise des wallets) : aucune
     * conversion n'est appliquée tant que l'admin ne change pas explicitement la
     * devise d'un opérateur — comportement actuel strictement préservé.
     */
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table) {
            $table->string('currency', 3)->default('XAF')->after('code');
        });

        Schema::table('operators', function (Blueprint $table) {
            // Nouvel index créé avant la suppression de l'ancien : sous MySQL, la
            // clé étrangère country_id exige qu'un index commençant par country_id existe.
            $table->unique(['country_id', 'code', 'currency']);
            $table->dropUnique(['country_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table) {
            $table->unique(['country_id', 'code']);
            $table->dropUnique(['country_id', 'code', 'currency']);
        });

        Schema::table('operators', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
