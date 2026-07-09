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
        Schema::create('agencies', function (Blueprint $table) {
            $table->id();

            // Le code unique de l'agence (ex: 'AGENCY_DLA_01') qui sera stocké dans le QR Code
            $table->string('code')->unique();
            $table->string('name');
            $table->string('city')->nullable(); // Utile pour filtrer (ex: Douala, Yaoundé)
            $table->string('address')->nullable();

            // Statut de l'agence (active, inactive)
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            // Indexation pour accélérer les recherches lors des scans Flutter
            $table->index(['code', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agencies');
    }
};
