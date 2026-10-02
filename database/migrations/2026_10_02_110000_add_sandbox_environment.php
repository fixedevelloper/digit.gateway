<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sandbox simulée des marchands : les opérations faites avec une clé sk_test_
     * sont des transactions 'sandbox', jamais envoyées à Digitwave, qui débitent et
     * créditent un solde fictif distinct (wallets.sandbox_balance) — jamais le vrai.
     */
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->decimal('sandbox_balance', 15, 2)->default(0.00)->after('balance');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('environment')->default('production')->after('channel')->index();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('environment');
        });

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('sandbox_balance');
        });
    }
};
