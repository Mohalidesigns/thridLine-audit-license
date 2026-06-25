<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\RevocationList;
use Illuminate\Console\Command;

class ApplyRevocationsCommand extends Command
{
    protected $signature = 'licenses:apply-revocations';
    protected $description = 'Apply scheduled revokes whose effective time has passed (flip status + deactivate activations)';

    public function handle(): int
    {
        // Revocations now in effect that have not yet been applied or cancelled.
        $due = RevocationList::query()
            ->whereNull('cancelled_at')
            ->whereNull('applied_at')
            ->whereNotNull('effective_at')
            ->where('effective_at', '<=', now())
            ->with('license')
            ->get();

        $count = 0;

        foreach ($due as $revocation) {
            $license = $revocation->license;

            // License gone (soft-deleted) — just mark the row applied so we
            // stop reconsidering it.
            if (! $license) {
                $revocation->update(['applied_at' => now()]);
                continue;
            }

            $affected = 0;
            if ($license->status !== 'revoked') {
                $license->update(['status' => 'revoked']);

                $affected = $license->activeActivations()->count();
                $license->activeActivations()->update([
                    'status' => 'deactivated',
                    'deactivated_at' => now(),
                ]);

                AuditLog::record('license.revoke_applied', 'license', $license->id, [
                    'revocation_id' => $revocation->id,
                    'reason' => $revocation->reason,
                    'scheduled_at' => $revocation->revoked_at?->toISOString(),
                    'affected_activations' => $affected,
                ], 'system');
            }

            $revocation->update(['applied_at' => now()]);
            $count++;
        }

        $this->info("Applied {$count} scheduled revocation(s).");

        return self::SUCCESS;
    }
}
