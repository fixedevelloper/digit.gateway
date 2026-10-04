<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document', 20); // terms | privacy
            $table->string('version', 20);
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'document', 'version']);
        });

        // Reprise des acceptations déjà enregistrées sur la table users
        foreach (['terms', 'privacy'] as $doc) {
            $rows = DB::table('users')
                ->whereNotNull("{$doc}_version")
                ->whereNotNull("{$doc}_accepted_at")
                ->get(['id', "{$doc}_version as version", "{$doc}_accepted_at as accepted_at"]);

            foreach ($rows as $r) {
                DB::table('legal_acceptances')->insert([
                    'user_id' => $r->id,
                    'document' => $doc,
                    'version' => $r->version,
                    'accepted_at' => $r->accepted_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
    }
};
