<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('contact_email');
            $table->string('industry')->nullable();
            $table->string('country')->default('NG');
            // json (MySQL has no jsonb; Postgres maps json fine too).
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('slug');
            $table->index('country');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
