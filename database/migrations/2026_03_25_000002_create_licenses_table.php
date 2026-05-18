<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('license_key', 64)->unique();
            $table->string('plan'); // 'starter', 'professional', 'enterprise'
            $table->json('features');
            $table->unsignedInteger('max_users')->default(5);
            $table->unsignedInteger('max_activations')->default(1);
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->string('status')->default('active'); // active, suspended, revoked, expired
            $table->uuid('issued_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['org_id', 'status']);
            $table->index('license_key');
            $table->index('expires_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
