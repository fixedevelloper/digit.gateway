<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('country_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->string('service', 30); // MOBILE_MONEY | BANK_TRANSFER
            $table->string('status', 20)->default('INACTIVE'); // ACTIVE | INACTIVE | MANUAL
            $table->foreignId('provider_id')->nullable()->constrained('providers')->nullOnDelete();

            // Limites (devise du wallet de l'expéditeur) ; null = pas de limite configurée.
            $table->decimal('min_amount', 15, 2)->nullable();
            $table->decimal('max_amount', 15, 2)->nullable();
            $table->decimal('daily_limit', 15, 2)->nullable();
            $table->decimal('monthly_limit', 15, 2)->nullable();
            $table->timestamps();

            $table->unique(['country_id', 'service']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('country_services');
    }
};
