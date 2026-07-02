<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deployment registry metadata: where an activation runs (domain), what it
 * runs (app_version, app_env). Reported by the consumer on activate and kept
 * fresh on every heartbeat, so the admin Deployments view can show the fleet.
 * All nullable — older consumers that don't report them keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_activations', function (Blueprint $table) {
            $table->string('domain')->nullable()->after('hostname');
            $table->string('app_version', 50)->nullable()->after('os_info');
            $table->string('app_env', 20)->nullable()->after('app_version');
            $table->index('domain');
        });
    }

    public function down(): void
    {
        Schema::table('license_activations', function (Blueprint $table) {
            $table->dropIndex(['domain']);
            $table->dropColumn(['domain', 'app_version', 'app_env']);
        });
    }
};
