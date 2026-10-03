<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // L'enum MySQL ne peut pas recevoir les statuts du traitement manuel
        // (pending_manual_review, assigned, rejected, cancelled) : passage en chaîne.
        // Les valeurs existantes (pending, processing, success, failed, reversed) sont conservées.
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('status', 32)->default('pending')->change();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('service', 30)->default('MOBILE_MONEY')->after('type')->index();
            $table->string('processing_mode', 20)->default('AUTOMATIC')->after('service')->index();
            $table->foreignId('provider_id')->nullable()->after('processing_mode')->constrained('providers')->nullOnDelete();
            $table->string('provider_reference')->nullable()->after('provider_id');
            $table->foreignId('destination_country_id')->nullable()->after('provider_reference')->constrained('countries')->nullOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()->after('destination_country_id')->constrained('users')->nullOnDelete();
            $table->foreignId('processed_by')->nullable()->after('assigned_agent_id')->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable()->after('processed_by');
            $table->text('rejection_reason')->nullable()->after('processed_at');
            $table->string('priority', 10)->default('normal')->after('rejection_reason');

            // Idempotence durable (en base, contrairement au cache) : une même clé ne crée jamais
            // deux transferts pour le même utilisateur.
            $table->string('idempotency_key', 100)->nullable()->after('priority');
            $table->unique(['user_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
            $table->dropColumn(['priority', 'rejection_reason', 'processed_at', 'provider_reference']);
            $table->dropConstrainedForeignId('processed_by');
            $table->dropConstrainedForeignId('assigned_agent_id');
            $table->dropConstrainedForeignId('destination_country_id');
            $table->dropConstrainedForeignId('provider_id');
            $table->dropColumn(['processing_mode', 'service']);
        });
    }
};
