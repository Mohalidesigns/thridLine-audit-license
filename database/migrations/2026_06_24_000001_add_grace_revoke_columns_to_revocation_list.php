<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grace-delay / scheduled revokes with cancel.
     *
     * - effective_at already exists: when set in the future, the revoke is
     *   "scheduled" and only takes effect once that time passes.
     * - cancelled_at / cancelled_by: a pending (future-effective) revoke that
     *   an admin cancelled before it took effect. Cancelled rows are ignored
     *   by all enforcement checks.
     * - applied_at: bookkeeping — when the scheduled revoke was actually
     *   applied (license status flipped + activations deactivated) by the
     *   apply-revocations command. Lets that command stay idempotent.
     */
    public function up(): void
    {
        Schema::table('revocation_list', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('effective_at');
            $table->uuid('cancelled_by')->nullable()->after('cancelled_at');
            $table->timestamp('applied_at')->nullable()->after('cancelled_by');

            $table->index('effective_at');
        });
    }

    public function down(): void
    {
        Schema::table('revocation_list', function (Blueprint $table) {
            $table->dropIndex(['effective_at']);
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'applied_at']);
        });
    }
};
