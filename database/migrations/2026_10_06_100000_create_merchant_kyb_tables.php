<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // incomplete | in_review | approved | rejected  (marchands uniquement)
            $table->string('kyb_status', 20)->default('incomplete')->after('environment');
            $table->foreignId('kyb_reviewed_by')->nullable()->after('kyb_status')->constrained('users')->nullOnDelete();
            $table->timestamp('kyb_reviewed_at')->nullable()->after('kyb_reviewed_by');
            $table->string('kyb_rejection_reason')->nullable()->after('kyb_reviewed_at');
            // Marchands déjà en production à la mise en place : délai pour compléter le dossier (pas de suspension automatique).
            $table->timestamp('kyb_grace_until')->nullable()->after('kyb_rejection_reason');
        });

        Schema::create('merchant_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('registration_number', 100)->nullable();
            $table->string('tax_id', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('address', 500)->nullable();
            $table->text('business_description')->nullable();
            $table->decimal('expected_monthly_volume', 15, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('merchant_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('disk', 20);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->string('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'type']); // une pièce courante par type ; l'historique est dans merchant_kyb_events
        });

        // Journal append-only : qui a déposé, vu, approuvé ou refusé quoi, et quand.
        Schema::create('merchant_kyb_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('document_type', 40)->nullable();
            $table->string('comment', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });

        // Marchands déjà en production : dossier à compléter dans le délai de grâce (voir config/kyb.php).
        DB::table('users')->where('role', 'merchant')->where('environment', 'production')
            ->update(['kyb_grace_until' => now()->addDays((int) config('kyb.grace_days', 30))]);
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_kyb_events');
        Schema::dropIfExists('merchant_documents');
        Schema::dropIfExists('merchant_profiles');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kyb_reviewed_by');
            $table->dropColumn(['kyb_status', 'kyb_reviewed_at', 'kyb_rejection_reason', 'kyb_grace_until']);
        });
    }
};
