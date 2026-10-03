<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Journal append-only : le modèle interdit toute modification/suppression.
        Schema::create('transfer_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 30)->nullable();
            $table->string('action', 50)->index();
            $table->string('old_status', 32)->nullable();
            $table->string('new_status', 32)->nullable();
            $table->text('comment')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_audit_logs');
    }
};
