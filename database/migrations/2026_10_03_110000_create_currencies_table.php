<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();
            $table->string('name', 100);
            $table->string('symbol', 10);
            $table->timestamps();
        });

        $now = now();
        DB::table('currencies')->insert(array_map(fn (array $c) => $c + ['created_at' => $now, 'updated_at' => $now], [
            ['code' => 'XAF', 'name' => 'Franc CFA (CEMAC)', 'symbol' => 'FCFA'],
            ['code' => 'XOF', 'name' => 'Franc CFA (UEMOA)', 'symbol' => 'CFA'],
            ['code' => 'USD', 'name' => 'Dollar américain', 'symbol' => '$'],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€'],
            ['code' => 'CDF', 'name' => 'Franc congolais', 'symbol' => 'FC'],
            ['code' => 'TZS', 'name' => 'Shilling tanzanien', 'symbol' => 'TSh'],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
