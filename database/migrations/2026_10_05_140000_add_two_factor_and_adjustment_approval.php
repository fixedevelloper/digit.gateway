<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();            // chiffré (cast)
            $table->text('two_factor_recovery_codes')->nullable();    // hashés, chiffrés (cast)
            $table->timestamp('two_factor_confirmed_at')->nullable(); // non nul = 2FA active
            $table->unsignedBigInteger('two_factor_last_step')->nullable(); // anti-rejeu d'un code déjà utilisé
        });

        Schema::table('wallet_adjustments', function (Blueprint $table) {
            // Les lignes existantes sont des ajustements déjà appliqués.
            $table->string('status', 20)->default('approved')->after('reason'); // pending | approved | rejected
            $table->foreignId('reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('rejection_reason')->nullable()->after('reviewed_at');
            // Renseignés seulement à l'application de l'ajustement.
            $table->decimal('balance_before', 15, 2)->nullable()->change();
            $table->decimal('balance_after', 15, 2)->nullable()->change();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_adjustments', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['status', 'reviewed_at', 'rejection_reason']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step']);
        });
    }
};
