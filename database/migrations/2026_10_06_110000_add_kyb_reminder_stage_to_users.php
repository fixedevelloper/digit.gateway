<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Dernier rappel d'échéance KYB envoyé (0 = aucun) : évite d'envoyer deux fois le même.
            $table->unsignedTinyInteger('kyb_reminder_stage')->default(0)->after('kyb_grace_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('kyb_reminder_stage');
        });
    }
};
