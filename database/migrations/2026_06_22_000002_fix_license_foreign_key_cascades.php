<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The original migrations left several child FKs at the default RESTRICT, which
 * blocks deleting a license/org that has metrics or clients, and is inconsistent
 * with licenses.org_id (which cascades). Re-create them with explicit semantics:
 *  - usage metrics cascade with their license/activation (telemetry is disposable)
 *  - api_clients cascade with their org
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_usage_metrics', function (Blueprint $table) {
            $table->dropForeign(['license_id']);
            $table->dropForeign(['activation_id']);
            $table->foreign('license_id')->references('id')->on('licenses')->cascadeOnDelete();
            $table->foreign('activation_id')->references('id')->on('license_activations')->cascadeOnDelete();
        });

        Schema::table('api_clients', function (Blueprint $table) {
            $table->dropForeign(['org_id']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('license_usage_metrics', function (Blueprint $table) {
            $table->dropForeign(['license_id']);
            $table->dropForeign(['activation_id']);
            $table->foreign('license_id')->references('id')->on('licenses')->restrictOnDelete();
            $table->foreign('activation_id')->references('id')->on('license_activations')->restrictOnDelete();
        });

        Schema::table('api_clients', function (Blueprint $table) {
            $table->dropForeign(['org_id']);
            $table->foreign('org_id')->references('id')->on('organizations')->restrictOnDelete();
        });
    }
};
