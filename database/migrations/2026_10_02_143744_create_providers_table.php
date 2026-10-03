<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique(); // Clé technique (ex: digitwave), liée à une implémentation dans config/transfers.php
            $table->string('name');
            $table->json('services')->nullable(); // Services supportés : MOBILE_MONEY, BANK_TRANSFER
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
