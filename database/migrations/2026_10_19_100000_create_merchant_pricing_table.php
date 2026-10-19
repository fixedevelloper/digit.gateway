<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tarifs propres à un marchand, par opérateur (donc par pays). Remplacent, pour ce marchand,
     * les frais de l'opérateur et le taux de change global (qui ne concernent que les clients de l'app).
     * Frais en devise de l'opérateur ; `rate` = unités de devise wallet pour 1 unité de devise opérateur
     * (requis seulement si les deux devises diffèrent).
     */
    public function up(): void
    {
        Schema::create('merchant_pricing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('operators')->cascadeOnDelete();
            $table->decimal('fixed_fee', 15, 2)->default(0);
            $table->decimal('percent_fee', 8, 4)->default(0); // 0.0100 = 1 %
            $table->decimal('rate', 20, 8)->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['merchant_id', 'operator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_pricing');
    }
};
