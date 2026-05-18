<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\LicenseActivation;
use Illuminate\Console\Command;

class HeartbeatAlertsCommand extends Command
{
    protected $signature = 'licenses:heartbeat-alerts';
    protected $description = 'Alert on missed heartbeats';

    public function handle(): int
    {
        $threshold = now()->subHours(config('licensing.heartbeat_interval_hours') * 2);

        $missedHeartbeats = LicenseActivation::where('status', 'active')
            ->where('last_seen_at', '<', $threshold)
            ->with('license')
            ->get();

        foreach ($missedHeartbeats as $activation) {
            AuditLog::record('heartbeat.missed', 'activation', $activation->id, [
                'license_id' => $activation->license_id,
                'last_seen_at' => $activation->last_seen_at?->toISOString(),
                'threshold' => $threshold->toISOString(),
            ], 'system');
        }

        $count = $missedHeartbeats->count();
        $this->info("Found {$count} activations with missed heartbeats.");

        return self::SUCCESS;
    }
}
