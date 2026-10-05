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
            $table->unsignedTinyInteger('kyc_level')->default(1)->after('role');
        });

        // Plafonds par niveau, exprimés dans la devise du wallet (XAF). NULL = illimité.
        Schema::create('kyc_limits', function (Blueprint $table) {
            $table->unsignedTinyInteger('level')->primary();
            $table->decimal('per_transaction', 15, 2)->nullable();
            $table->decimal('daily_limit', 15, 2)->nullable();
            $table->decimal('monthly_limit', 15, 2)->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('kyc_limits')->insert([
            ['level' => 1, 'per_transaction' => 100000, 'daily_limit' => 200000, 'monthly_limit' => 500000, 'created_at' => $now, 'updated_at' => $now],
            ['level' => 2, 'per_transaction' => 500000, 'daily_limit' => 1000000, 'monthly_limit' => 5000000, 'created_at' => $now, 'updated_at' => $now],
            ['level' => 3, 'per_transaction' => null, 'daily_limit' => null, 'monthly_limit' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::create('kyc_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('target_level');
            $table->string('document_type', 30);
            $table->string('document_number', 100)->nullable();
            $table->string('full_name');
            $table->date('birth_date')->nullable();
            $table->json('files'); // [{role, disk, path, mime, size}]
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_submissions');
        Schema::dropIfExists('kyc_limits');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('kyc_level');
        });
    }
};
