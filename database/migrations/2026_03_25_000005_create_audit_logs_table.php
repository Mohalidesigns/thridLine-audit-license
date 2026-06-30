<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('action');
            $table->string('actor_type');
            $table->uuid('actor_id')->nullable();
            $table->string('resource_type');
            $table->uuid('resource_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->dateTime('created_at');

            $table->index(['action', 'created_at']);
            $table->index(['resource_type', 'resource_id']);
            $table->index('actor_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
