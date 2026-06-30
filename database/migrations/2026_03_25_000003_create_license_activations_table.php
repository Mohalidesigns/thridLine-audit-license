<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_activations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('license_id')->constrained('licenses')->cascadeOnDelete();
            $table->string('device_fingerprint', 128);
            $table->string('hostname')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('os_info')->nullable();
            $table->dateTime('activated_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->string('status')->default('active'); // active, deactivated
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['license_id', 'device_fingerprint']);
            $table->index('device_fingerprint');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_activations');
    }
};
