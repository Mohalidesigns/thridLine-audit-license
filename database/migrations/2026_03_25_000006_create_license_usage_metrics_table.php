<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_usage_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('license_id')->constrained('licenses');
            $table->foreignUuid('activation_id')->constrained('license_activations');
            $table->unsignedInteger('active_users_count');
            $table->json('feature_usage');
            $table->dateTime('reported_at');
            $table->timestamps();

            $table->index(['license_id', 'reported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_usage_metrics');
    }
};
