<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_beneficiaries', function (Blueprint $table) {
            $table->string('reference', 100)->nullable()->after('city');
            $table->string('pix', 100)->nullable()->after('reference');
            $table->string('bre_b', 100)->nullable()->after('pix');
            $table->string('spei', 18)->nullable()->after('bre_b');
        });
    }

    public function down(): void
    {
        Schema::table('bank_beneficiaries', function (Blueprint $table) {
            $table->dropColumn(['reference', 'pix', 'bre_b', 'spei']);
        });
    }
};
