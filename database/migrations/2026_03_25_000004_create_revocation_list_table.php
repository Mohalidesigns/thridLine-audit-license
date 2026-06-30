<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revocation_list', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('license_id')->constrained('licenses')->cascadeOnDelete();
            $table->string('reason');
            $table->uuid('revoked_by');
            $table->dateTime('revoked_at');
            $table->timestamp('effective_at')->nullable();
            $table->timestamps();

            $table->index('license_id');
            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revocation_list');
    }
};
