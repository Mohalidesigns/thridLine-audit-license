<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            // Issuance intent, orthogonal to "plan". full|trial|demo|poc|grace.
            $table->string('type')->default('full')->after('plan');
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropIndex(['type', 'status']);
            $table->dropColumn('type');
        });
    }
};
