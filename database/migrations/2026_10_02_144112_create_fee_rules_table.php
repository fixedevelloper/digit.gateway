<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->nullable()->constrained('countries')->cascadeOnDelete(); // null = tous les pays
            $table->string('service', 30);
            $table->foreignId('provider_id')->nullable()->constrained('providers')->cascadeOnDelete(); // null = tous les providers
            $table->string('currency', 3); // devise du wallet de l'expéditeur
            $table->decimal('min_amount', 15, 2)->default(0);
            $table->decimal('max_amount', 15, 2)->nullable(); // null = sans plafond
            $table->decimal('fixed_fee', 15, 2)->default(0);
            $table->decimal('percent_fee', 8, 4)->default(0); // 0.0100 = 1 %
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['service', 'currency', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_rules');
    }
};
