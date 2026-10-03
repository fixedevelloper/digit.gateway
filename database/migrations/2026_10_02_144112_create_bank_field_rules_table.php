<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Champs bancaires obligatoires par pays (ex: IBAN + BIC en Europe, account_number +
        // bank_code au Cameroun). Sans ligne pour un pays, config('transfers.bank_default_required').
        Schema::create('bank_field_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->string('field', 30);
            $table->boolean('required')->default(true);
            $table->timestamps();

            $table->unique(['country_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_field_rules');
    }
};
